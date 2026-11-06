<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Models\WorkflowRun;
use App\Services\ModelSelection;
use App\Services\OpenRouterProviderRouting;
use App\Services\ProviderConnectionStatus;
use App\Services\WorkflowRunFiles;
use App\Services\WorkflowRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

/**
 * Installs a fake `opencode` and a `node` wrapper in `<root>/bin` and points the command path at it. The fake answers with the first rule
 * whose `match` substrings all occur in the prompt (and whose `model` occurs in `--model`): a fixed text, a demo page
 * (`html`, with `__TITLE__` replaced by the prompt's `TITLE:` line) or, with `hang`, a partial text and no end, with a
 * grandchild that ignores SIGTERM. Usage is reported unless `usage` is false. Every call is recorded in
 * `<root>/fake/calls`; the fake exits with 91 when an application secret reached its environment.
 *
 * @param  list<array{match: list<string>, model?: string, text?: string, html?: string, usage?: bool, hang?: bool}>  $rules
 */
function installWorkflowEndToEndFakeCli(string $root, array $rules): void
{
    $source = <<<'JS'
if (process.env.APP_KEY !== undefined || process.env.BENCHFORGE_TEST_WORKFLOW_E2E_SECRET !== undefined) process.exit(91);
const fakeDirectory = __FAKE_DIRECTORY__;
const rules = __RULES__;
if (process.argv[2] === "api") {
  // The worker reads every model's real token limits once: `opencode api --standalone GET /api/model`.
  (await import("node:fs")).writeFileSync(`${fakeDirectory}/lookups/${process.pid}.json`, JSON.stringify({ args: process.argv.slice(2), home: process.env.HOME }));
  const limit = { context: 200000, output: 50000 };
  const listed = (providerID, modelID) => ({ id: modelID, modelID, providerID, type: "chat", limit });
  process.stdout.write(JSON.stringify({ location: { directory: process.cwd() }, data: [
    listed("openrouter", "fixture/e2e-alpha"), listed("opencode-go", "e2e-beta"), listed("opencode-go", "e2e-stop"),
  ] }));
  process.exit(0);
}
let prompt = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => { prompt += chunk; });
process.stdin.on("end", async () => {
  const { writeFileSync } = await import("node:fs");
  const { spawn } = await import("node:child_process");
  const args = process.argv.slice(2);
  const model = args[args.indexOf("--model") + 1];
  // The output limit the worker wrote into the call's configuration for this model, or null.
  const outputLimitOf = (fullModel) => {
    const slash = fullModel.indexOf("/");
    try {
      return JSON.parse(process.env.OPENCODE_CONFIG_CONTENT).provider[fullModel.slice(0, slash)].models[fullModel.slice(slash + 1)].limit.output ?? null;
    } catch {
      return null;
    }
  };
  const rule = rules.find((candidate) => candidate.match.every((text) => prompt.includes(text))
    && (candidate.model === undefined || model.includes(candidate.model)));
  writeFileSync(`${fakeDirectory}/calls/${process.pid}.json`, JSON.stringify({
    pid: process.pid,
    model,
    title: args[args.indexOf("--title") + 1],
    home: process.env.HOME,
    config: process.env.OPENCODE_CONFIG,
    outputLimit: outputLimitOf(model),
    matched: rule !== undefined,
  }));
  const emit = (event) => process.stdout.write(`${JSON.stringify({ sessionID: `ses_fake${process.pid}`, ...event })}\n`);
  if (rule === undefined) {
    emit({ type: "text", part: { id: "answer", text: "UNMATCHED PROMPT" } });
    return;
  }
  if (rule.hang) {
    const grandchild = spawn(process.execPath, ["-e", "process.on('SIGTERM', () => {}); setInterval(() => {}, 1000)"], { stdio: "ignore" });
    writeFileSync(`${fakeDirectory}/grandchildren/${grandchild.pid}`, "");
    emit({ type: "text", part: { id: "answer", text: rule.text } });
    setInterval(() => {}, 1000);
    return;
  }
  const title = /^TITLE: (.+)$/m.exec(prompt)?.[1] ?? "Demo";
  const text = rule.html === undefined ? rule.text : "```html\n" + rule.html.replaceAll("__TITLE__", title) + "\n```";
  emit({ type: "text", part: { id: "answer", text } });
  if (rule.usage !== false) {
    emit({ type: "step_finish", part: { cost: 0.001, tokens: { input: 10, output: 5 }, reason: "stop" } });
  }
});
JS;

    $fakeDirectory = $root.'/fake';
    $nodeBinary = installWorkflowEndToEndNodeWrapper($root.'/bin');
    File::ensureDirectoryExists($fakeDirectory.'/calls', 0700, true);
    File::ensureDirectoryExists($fakeDirectory.'/lookups', 0700, true);
    File::ensureDirectoryExists($fakeDirectory.'/grandchildren', 0700, true);
    File::put($root.'/bin/opencode', '#!'.$nodeBinary.PHP_EOL.str_replace(
        ['__FAKE_DIRECTORY__', '__RULES__'],
        [
            json_encode($fakeDirectory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ],
        $source,
    ));
    chmod($root.'/bin/opencode', 0755);
    config()->set('benchforge.command_path', $root.'/bin');
}

/**
 * Puts a `node` wrapper for the real Node.js into `$binPath` and makes it the configured Node.js. `BenchForgeSettings::apply()`
 * puts the Node.js directory first in the command path, so the fake `opencode` beside the wrapper always wins over a real
 * `opencode` installed next to the real Node.js (Homebrew, npm -g). Returns the real Node.js for the fake's shebang.
 */
function installWorkflowEndToEndNodeWrapper(string $binPath): string
{
    $nodeBinary = (string) config('benchforge.node_binary');

    expect($nodeBinary)->toStartWith('/')
        ->and(is_executable($nodeBinary))->toBeTrue();

    File::ensureDirectoryExists($binPath, 0700, true);
    File::put($binPath.'/node', "#!/bin/sh\nexec ".escapeshellarg($nodeBinary)." \"\$@\"\n");
    chmod($binPath.'/node', 0700);
    config()->set('benchforge.node_binary', $binPath.'/node');

    return $nodeBinary;
}

/**
 * The OpenCode binary the runner wrote into the run's job, i.e. the one the worker calls.
 */
function workflowEndToEndJobOpenCodeBinary(WorkflowRun $run): mixed
{
    $job = json_decode(File::get($run->working_directory.'/job.json'), true, flags: JSON_THROW_ON_ERROR);

    return $job['opencode']['binary'] ?? null;
}

/**
 * @return list<array{pid: int, model: string, title: string, home: string, config: string, outputLimit: int|null, matched: bool}>
 */
function workflowEndToEndCalls(string $root): array
{
    return collect(File::files($root.'/fake/calls'))
        ->map(fn (SplFileInfo $file): array => json_decode(File::get($file->getPathname()), true, flags: JSON_THROW_ON_ERROR))
        ->sortBy('pid')
        ->values()
        ->all();
}

/**
 * The model-limit lookups (`opencode api`) the worker made: one per model, never one per call.
 *
 * @return list<array{args: list<string>, home: string}>
 */
function workflowEndToEndLookups(string $root): array
{
    return collect(File::files($root.'/fake/lookups'))
        ->map(fn (SplFileInfo $file): array => json_decode(File::get($file->getPathname()), true, flags: JSON_THROW_ON_ERROR))
        ->values()
        ->all();
}

/**
 * The pids of every fake CLI process and of the grandchildren they started.
 *
 * @return list<int>
 */
function workflowEndToEndFakePids(string $root): array
{
    return [
        ...array_column(workflowEndToEndCalls($root), 'pid'),
        ...array_map(fn (SplFileInfo $file): int => (int) $file->getFilename(), File::files($root.'/fake/grandchildren')),
    ];
}

function workflowEndToEndProcessIsAlive(int $pid): bool
{
    return $pid > 1 && posix_kill($pid, 0);
}

function waitForWorkflowEndToEnd(Closure $condition, int $seconds): mixed
{
    $deadline = microtime(true) + $seconds;

    do {
        $value = $condition();

        if ($value !== null && $value !== false) {
            return $value;
        }

        usleep(50_000);
    } while (microtime(true) < $deadline);

    return $condition();
}

/**
 * One idea in the shape the Game Idea Factory schema requires.
 *
 * @return array<string, mixed>
 */
function workflowEndToEndIdea(string $title): array
{
    return [
        'title' => $title,
        'references' => [],
        'pitch' => "{$title} kısa ve eğlenceli bir oyun.",
        'coreLoop' => 'Dokun, kaç, puan topla.',
        'controls' => 'Tek parmakla sürükle.',
        'first10Seconds' => 'Oyuncu hemen sürüklemeye başlar.',
        'sessionLength' => '60 sn turlar',
        'whyReturn' => 'En iyi skoru geçmek.',
        'hook' => 'Büyük ve tatmin edici çarpışmalar.',
        'rewardedAdMoments' => ['Çarpışmadan sonra devam'],
        'hitPotential' => ['rating' => 'medium', 'reasons' => ['Anında eğlence'], 'risks' => ['Kalabalık tür']],
        'platformFit' => 'Playables için uygun.',
        'demoSpec' => "{$title} için tek seviye ve skor.",
    ];
}

function workflowEndToEndFenced(string $language, mixed $value): string
{
    $body = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return "Here it is.\n```{$language}\n{$body}\n```";
}

function workflowEndToEndDemoHtml(string $title = '__TITLE__'): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{$title}</title></head>
<body>
<canvas id="game"></canvas>
<script>
const canvas = document.getElementById('game');
canvas.addEventListener('pointerdown', (event) => event.preventDefault());
window.addEventListener('resize', () => {});
function frame() { requestAnimationFrame(frame); }
frame();
</script>
</body>
</html>
HTML;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workflowEndToEndInput(array $overrides = []): array
{
    return [
        'template_id' => 'game-idea-factory',
        'template_version' => 1,
        'inputs' => ['ideasPerCategory' => '1', 'docLanguage' => 'Türkçe', 'extraBrief' => ''],
        'max_parallel' => 2,
        'max_concurrent_calls' => 4,
        'timeouts' => ['llm' => null, 'code' => null],
        ...$overrides,
    ];
}

test('it runs the built-in Game Idea Factory end to end for two models through the real worker', function () {
    Http::preventStrayRequests();
    $root = storage_path('framework/testing/workflow-e2e-'.Str::uuid());
    $categories = ['Existing' => 'EXISTING GAME', 'Original' => 'ORIGINAL GAME', 'Hybrid' => 'HYBRID', 'Reimagined' => 'REIMAGINED'];
    $models = [
        'alpha' => ['match' => 'e2e-alpha', 'reportsScoreUsage' => true],
        'beta' => ['match' => 'e2e-beta', 'reportsScoreUsage' => false],
    ];
    $rules = [];

    foreach ($models as $prefix => $model) {
        $titles = array_map(fn (string $category): string => Str::title($prefix).' '.$category, array_keys($categories));
        $rules[] = [
            'match' => ['Rate every game idea below'],
            'model' => $model['match'],
            'text' => workflowEndToEndFenced('json', ['scores' => array_map(
                fn (string $title): array => ['title' => $title, 'score' => 7, 'verdict' => 'İyi.', 'reasoning' => 'Sade ve eğlenceli.'],
                $titles,
            )]),
            'usage' => $model['reportsScoreUsage'],
        ];

        foreach (array_values($categories) as $index => $brief) {
            $rules[] = [
                'match' => ["CATEGORY:\n{$brief}"],
                'model' => $model['match'],
                'text' => workflowEndToEndFenced('json', ['ideas' => [workflowEndToEndIdea($titles[$index])]]),
            ];
        }
    }

    $rules[] = ['match' => ['Build a playable one-file HTML5 demo'], 'html' => workflowEndToEndDemoHtml()];
    installWorkflowEndToEndFakeCli($root, $rules);
    File::put(config('benchforge.root_path').'/config/uctan-uca.conf', "alpha|openrouter/fixture/e2e-alpha|3101\nbeta|opencode-go/e2e-beta|3102\n");
    OpenRouterModel::factory()->create(['model_id' => 'fixture/e2e-alpha', 'name' => 'Alfa Uçtan Uca']);
    OpenCodeGoModel::factory()->create(['model_id' => 'e2e-beta', 'name' => 'Beta Uçtan Uca']);
    mock(ProviderConnectionStatus::class)->shouldReceive('inspect')->andReturn([
        'cli' => ['status' => 'pass'],
        'providers' => ['openrouter' => ['status' => 'pass'], 'opencode-go' => ['status' => 'pass']],
    ]);
    mock(OpenRouterProviderRouting::class)->shouldReceive('routingForModels')->once()
        ->with(['fixture/e2e-alpha'], 'auto')
        ->andReturn(['fixture/e2e-alpha' => ['allow_fallbacks' => true, 'data_collection' => 'deny', 'require_parameters' => true]]);
    $runner = app(WorkflowRunner::class);
    $files = app(WorkflowRunFiles::class);
    $alphaSlug = ModelSelection::folderFor('openrouter/fixture/e2e-alpha');
    $betaSlug = ModelSelection::folderFor('opencode-go/e2e-beta');
    $run = null;
    $originalEnvironment = $_ENV;
    $_ENV['APP_KEY'] = 'workflow-e2e-test-only-secret';
    $_ENV['BENCHFORGE_TEST_WORKFLOW_E2E_SECRET'] = 'workflow-e2e-unknown-test-only-secret';

    try {
        $run = $runner->start(workflowEndToEndInput(['model_mode' => 'group', 'config' => 'config/uctan-uca.conf']));

        expect(workflowEndToEndJobOpenCodeBinary($run))->toBe($root.'/bin/opencode');

        for ($attempt = 0; $attempt < 600 && $run->isActive(); $attempt++) {
            usleep(50_000);
            $run = $runner->reconcile($run);
        }

        $snapshot = $runner->snapshot($run);
        $gallery = $files->galleryItems($run);
        $ideaDetail = $files->stepDetail($run, $alphaSlug, 'ideas', '002', 'generate');
        $scoreDetail = $files->stepDetail($run, $betaSlug, 'score');
        $buildDetail = $files->stepDetail($run, $betaSlug, 'demos', '003', 'build');
        $checkDetail = $files->stepDetail($run, $betaSlug, 'demos', '003', 'check');
        $calls = workflowEndToEndCalls($root);

        expect($run->status)->toBe('completed')
            ->and($run->message)->toBe('İş akışı tamamlandı.')
            ->and($run->finished_at)->not->toBeNull()
            ->and($run->inputs)->toBe(['ideasPerCategory' => 1, 'docLanguage' => 'Türkçe', 'extraBrief' => ''])
            ->and($run->models->map->only([
                'slug', 'model', 'model_name', 'provider', 'status', 'error_message',
                'input_tokens', 'output_tokens', 'cost_usd', 'items_total', 'items_completed', 'items_failed',
            ])->all())->toBe([
                [
                    'slug' => $alphaSlug, 'model' => 'openrouter/fixture/e2e-alpha', 'model_name' => 'Alfa Uçtan Uca',
                    'provider' => 'openrouter', 'status' => 'completed', 'error_message' => null,
                    'input_tokens' => 90, 'output_tokens' => 45, 'cost_usd' => '0.009000',
                    'items_total' => 8, 'items_completed' => 8, 'items_failed' => 0,
                ],
                [
                    'slug' => $betaSlug, 'model' => 'opencode-go/e2e-beta', 'model_name' => 'Beta Uçtan Uca',
                    'provider' => 'opencode-go', 'status' => 'completed', 'error_message' => null,
                    'input_tokens' => null, 'output_tokens' => null, 'cost_usd' => null,
                    'items_total' => 8, 'items_completed' => 8, 'items_failed' => 0,
                ],
            ])
            ->and($run->models->pluck('duration_seconds')->filter()->count())->toBe(2);

        $outputLimits = collect($calls)->pluck('outputLimit')->filter()->unique()->values()->all();

        expect($calls)->toHaveCount(18)
            ->and(workflowEndToEndLookups($root))->toHaveCount(2)
            ->and(workflowEndToEndLookups($root)[0]['args'])->toBe(['api', '--standalone', 'GET', '/api/model'])
            // The text steps carry the step's 32.000 token cap below the models' own 50.000; code steps keep the model's limit.
            ->and($outputLimits)->toBe([32000])
            ->and(collect($calls)->every(fn (array $call): bool => $call['matched']))->toBeTrue()
            ->and(collect($calls)->countBy('model')->all())->toEqualCanonicalizing([
                'openrouter/fixture/e2e-alpha' => 9,
                'opencode-go/e2e-beta' => 9,
            ])
            ->and(array_column($calls, 'title'))->toContain(
                "benchforge:workflow:{$run->slug}:{$alphaSlug}:generate:001:1",
                "benchforge:workflow:{$run->slug}:{$betaSlug}:score:-:1",
                "benchforge:workflow:{$run->slug}:{$betaSlug}:build:004:1",
            )
            ->and(collect($calls)->firstWhere('model', 'opencode-go/e2e-beta'))->toMatchArray([
                'home' => $run->working_directory.'/models/'.$betaSlug.'/.benchforge-home',
                'config' => $run->working_directory.'/models/'.$betaSlug.'/opencode.json',
            ]);

        expect(array_keys($snapshot))->toBe(['status', 'active', 'message', 'startedAt', 'finishedAt', 'durationSeconds', 'totals', 'models', 'events'])
            ->and($snapshot)->toMatchArray(['status' => 'completed', 'active' => false, 'message' => 'İş akışı tamamlandı.'])
            ->and($snapshot['totals'])->toBe([
                'inputTokens' => null, 'outputTokens' => null, 'costUsd' => null, 'calls' => 18,
                'itemsTotal' => 16, 'itemsCompleted' => 16, 'itemsFailed' => 0,
            ])
            ->and(array_column($snapshot['models'], 'slug'))->toBe([$alphaSlug, $betaSlug])
            ->and(array_column($snapshot['models'], 'usage'))->toBe([
                ['inputTokens' => 90, 'outputTokens' => 45, 'costUsd' => 0.009, 'calls' => 9],
                ['inputTokens' => null, 'outputTokens' => null, 'costUsd' => null, 'calls' => 9],
            ])
            ->and(json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))->not->toContain($run->working_directory);

        foreach ($snapshot['models'] as $index => $modelSnapshot) {
            $prefix = $index === 0 ? 'Alpha' : 'Beta';
            [$ideas, $score, $demos] = $modelSnapshot['steps'];
            $lastOutput = $modelSnapshot['lastOutput'];
            $lastBuiltItem = collect($demos['items'])->firstWhere('key', $lastOutput['item'] ?? null);

            expect(array_keys($modelSnapshot))->toBe(['slug', 'model', 'name', 'status', 'message', 'durationSeconds', 'usage', 'items', 'steps', 'active', 'lastOutput'])
                ->and($modelSnapshot)->toMatchArray(['status' => 'completed', 'message' => null, 'items' => ['total' => 8, 'completed' => 8, 'failed' => 0], 'active' => []])
                ->and(array_map(fn (array $step): array => [$step['id'], $step['type'], $step['status']], $modelSnapshot['steps']))->toBe([
                    ['ideas', 'foreach', 'completed'],
                    ['score', 'llm_json', 'completed'],
                    ['demos', 'foreach', 'completed'],
                ])
                ->and(array_keys($ideas))->toBe(['id', 'type', 'title', 'status', 'message', 'durationSeconds', 'attempts', 'usage', 'counts', 'children', 'items'])
                ->and($ideas['children'])->toBe([['id' => 'generate', 'type' => 'llm_json', 'title' => 'Kategori fikirleri']])
                ->and($ideas['counts'])->toBe(['total' => 4, 'completed' => 4, 'failed' => 0, 'running' => 0, 'skipped' => 0, 'stopped' => 0])
                ->and(array_column($ideas['items'], 'label'))->toBe(['Var olan oyun', 'Özgün oyun', 'Melez oyun', 'Yeniden yorum'])
                ->and($score)->toMatchArray(['attempts' => 1, 'counts' => null, 'children' => null, 'items' => null])
                ->and($demos['children'])->toBe([
                    ['id' => 'build', 'type' => 'code_text', 'title' => 'Demo yaz'],
                    ['id' => 'check', 'type' => 'browser_check', 'title' => 'Tarayıcı testi'],
                ])
                ->and($demos['items'])->toBe(array_map(fn (string $key, string $category): array => [
                    'key' => $key,
                    'label' => "{$prefix} {$category}",
                    'status' => 'completed',
                    'steps' => ['build' => 'completed', 'check' => 'skipped'],
                ], ['001', '002', '003', '004'], array_keys($categories)))
                ->and($lastBuiltItem)->toBeArray()
                ->and($lastOutput)->toMatchArray([
                    'step' => 'demos',
                    'child' => 'build',
                    'label' => $lastBuiltItem['label'] ?? null,
                    'status' => 'completed',
                    'tail' => "```html\n".workflowEndToEndDemoHtml($lastBuiltItem['label'] ?? '')."\n```",
                ])
                ->and($lastOutput['finishedAt'])->toBeString();
        }

        expect($gallery)->toHaveCount(8);

        foreach ($gallery as $position => $card) {
            $modelSlug = $position < 4 ? $alphaSlug : $betaSlug;
            $key = sprintf('%03d', $position % 4 + 1);
            $title = ($position < 4 ? 'Alpha' : 'Beta').' '.array_keys($categories)[$position % 4];
            $demoPath = $files->demoPath($run, $modelSlug, 'demos', $key, 'build');

            expect($card)->toBe([
                'model' => $modelSlug,
                'modelName' => $position < 4 ? 'Alfa Uçtan Uca' : 'Beta Uçtan Uca',
                'step' => 'demos',
                'child' => 'build',
                'item' => $key,
                'title' => $title,
                'status' => 'completed',
                'message' => null,
                'checks' => ['critical' => [], 'warnings' => []],
                'bytes' => strlen(workflowEndToEndDemoHtml($title)),
                'demoUrl' => "/workflows/runs/{$run->slug}/demos/{$modelSlug}/demos/{$key}/build",
            ])
                ->and($demoPath)->toBe($run->working_directory."/models/{$modelSlug}/steps/demos/items/{$key}/build/demo.html")
                ->and(File::get($demoPath))->toBe(workflowEndToEndDemoHtml($title));
        }

        expect($ideaDetail)->toMatchArray([
            'model' => ['slug' => $alphaSlug, 'name' => 'Alfa Uçtan Uca'],
            'step' => ['id' => 'generate', 'type' => 'llm_json', 'title' => 'Kategori fikirleri'],
            'item' => ['key' => '002', 'label' => 'Özgün oyun'],
            'status' => 'completed',
            'message' => null,
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5, 'costUsd' => 0.001, 'calls' => 1],
            'output' => ['ideas' => [workflowEndToEndIdea('Alpha Original')]],
            'outputTruncated' => false,
            'demoUrl' => null,
        ])
            ->and($ideaDetail['attempts'])->toHaveCount(1)
            ->and($ideaDetail['attempts'][0])->toMatchArray([
                'n' => 1,
                'kind' => 'initial',
                'status' => 'completed',
                'error' => null,
                'promptTruncated' => false,
                'response' => workflowEndToEndFenced('json', ['ideas' => [workflowEndToEndIdea('Alpha Original')]]),
                'responseTruncated' => false,
            ])
            ->and($ideaDetail['attempts'][0]['prompt'])->toContain(
                "CATEGORY:\nORIGINAL GAME:",
                'Write exactly 1 game ideas for this category.',
                "DO NOT REPEAT (ideas from earlier categories; empty for the first category):\n- Alpha Existing\n",
                "\n\nOUTPUT FORMAT (mandatory):\n",
            );

        expect($scoreDetail)->toMatchArray([
            'step' => ['id' => 'score', 'type' => 'llm_json', 'title' => 'Fikirleri puanla'],
            'item' => null,
            'status' => 'completed',
            'usage' => ['inputTokens' => null, 'outputTokens' => null, 'costUsd' => null, 'calls' => 1],
        ])
            ->and($scoreDetail['output']['scores'])->toHaveCount(4)
            ->and($scoreDetail['attempts'][0]['prompt'])->toContain('"title": "Beta Existing"', '"title": "Beta Reimagined"');

        expect($buildDetail)->toMatchArray([
            'step' => ['id' => 'build', 'type' => 'code_text', 'title' => 'Demo yaz'],
            'item' => ['key' => '003', 'label' => 'Beta Hybrid'],
            'status' => 'completed',
            'output' => [
                'file' => 'demo.html',
                'bytes' => strlen(workflowEndToEndDemoHtml('Beta Hybrid')),
                'checks' => ['critical' => [], 'warnings' => []],
            ],
            'demoUrl' => "/workflows/runs/{$run->slug}/demos/{$betaSlug}/demos/003/build",
        ])
            ->and($buildDetail['attempts'][0]['prompt'])->toContain("TITLE: Beta Hybrid\nPITCH: Beta Hybrid kısa ve eğlenceli bir oyun.\n");

        expect($checkDetail)->toMatchArray([
            'step' => ['id' => 'check', 'type' => 'browser_check', 'title' => 'Tarayıcı testi'],
            'status' => 'skipped',
            'message' => "Tarayıcı testi Aşama 2'de etkinleşecek.",
            'attempts' => [],
            'demoUrl' => null,
        ]);

        expect($snapshot['events'])->toHaveCount(80)
            ->and(array_column($snapshot['events'], 'type'))->toContain('step_skipped', 'model_finished')
            ->and(end($snapshot['events']))->toMatchArray(['type' => 'run_finished', 'level' => 'info', 'model' => null]);
        Http::assertNothingSent();
    } finally {
        $_ENV = $originalEnvironment;

        if ($run instanceof WorkflowRun && $run->refresh()->isActive()) {
            try {
                $runner->stop($run);
            } catch (BenchmarkRunnerException) {
                // The worker may already be exiting between reconciliation and cleanup.
            }
        }

        File::deleteDirectory($root);
    }
});

test('it stops a running workflow, ends every fake CLI process and keeps the work after the stop pending', function () {
    Http::preventStrayRequests();
    $root = storage_path('framework/testing/workflow-e2e-stop-'.Str::uuid());
    installWorkflowEndToEndFakeCli($root, [
        ['match' => ['CATEGORY:'], 'hang' => true, 'text' => 'Fikirler düşünülüyor'],
    ]);
    $model = OpenCodeGoModel::factory()->create(['model_id' => 'e2e-stop', 'name' => 'Durdurma Modeli']);
    mock(ProviderConnectionStatus::class)->shouldReceive('inspect')->andReturn([
        'cli' => ['status' => 'pass'],
        'providers' => ['opencode-go' => ['status' => 'pass']],
    ]);
    $runner = app(WorkflowRunner::class);
    $files = app(WorkflowRunFiles::class);
    $slug = ModelSelection::folderFor('opencode-go/e2e-stop');
    $run = null;
    $originalEnvironment = $_ENV;
    $_ENV['APP_KEY'] = 'workflow-e2e-stop-test-only-secret';

    try {
        $run = $runner->start(workflowEndToEndInput(['model_mode' => 'opencode_go', 'go_models' => [$model->model_id], 'max_parallel' => 1]));

        expect(workflowEndToEndJobOpenCodeBinary($run))->toBe($root.'/bin/opencode');

        $running = waitForWorkflowEndToEnd(function () use ($runner, $run): ?array {
            $snapshot = $runner->snapshot($run);

            return ($snapshot['models'][0]['active'][0]['tail'] ?? null) === 'Fikirler düşünülüyor' ? $snapshot : null;
        }, 15);

        expect($running)->toBeArray()
            ->and($running['status'])->toBe('running')
            ->and($running['models'][0]['active'][0])->toMatchArray([
                'step' => 'ideas', 'child' => 'generate', 'item' => '001', 'label' => 'Var olan oyun', 'attempt' => 1,
            ])
            ->and(waitForWorkflowEndToEnd(fn (): bool => count(File::files($root.'/fake/grandchildren')) === 1, 5))->toBeTrue();

        $stoppingStatus = $runner->stop($run)->status;

        for ($attempt = 0; $attempt < 600 && $run->refresh()->isActive(); $attempt++) {
            usleep(50_000);
            $run = $runner->reconcile($run);
        }

        $snapshot = $runner->snapshot($run);
        $detail = $files->stepDetail($run, $slug, 'ideas', '001', 'generate');
        $pids = [$run->runner_pid, ...workflowEndToEndFakePids($root)];

        expect($stoppingStatus)->toBe('stopping')
            ->and($run->status)->toBe('stopped')
            ->and($run->message)->toBe('Çalıştırma durduruldu.')
            ->and($run->finished_at)->not->toBeNull()
            ->and($run->models->sole()->only(['slug', 'status', 'error_message']))->toBe([
                'slug' => $slug, 'status' => 'stopped', 'error_message' => 'Çalıştırma durduruldu.',
            ]);

        expect($snapshot)->toMatchArray(['status' => 'stopped', 'active' => false])
            ->and($snapshot['models'][0]['active'])->toBe([])
            ->and($snapshot['models'][0]['lastOutput'])->toBeNull()
            ->and(array_column($snapshot['models'][0]['steps'], 'status', 'id'))->toBe([
                'ideas' => 'stopped', 'score' => 'pending', 'demos' => 'pending',
            ])
            ->and($snapshot['models'][0]['steps'][0]['items'][0])->toBe([
                'key' => '001', 'label' => 'Var olan oyun', 'status' => 'stopped', 'steps' => ['generate' => 'stopped'],
            ])
            ->and(array_column($snapshot['events'], 'type'))->toContain('stop_requested')
            ->and(end($snapshot['events'])['type'])->toBe('run_finished');

        expect($detail)->toMatchArray(['status' => 'stopped', 'item' => ['key' => '001', 'label' => 'Var olan oyun']])
            ->and($detail['attempts'])->toHaveCount(1)
            ->and($detail['attempts'][0])->toMatchArray(['status' => 'stopped', 'response' => 'Fikirler düşünülüyor']);

        expect(workflowEndToEndCalls($root))->toHaveCount(1)
            ->and($pids)->toHaveCount(3)
            ->and(waitForWorkflowEndToEnd(fn (): bool => collect($pids)->doesntContain(
                fn (int $pid): bool => workflowEndToEndProcessIsAlive($pid),
            ), 5))->toBeTrue();
        Http::assertNothingSent();
    } finally {
        $_ENV = $originalEnvironment;

        if ($run instanceof WorkflowRun && is_int($run->refresh()->runner_pid) && workflowEndToEndProcessIsAlive($run->runner_pid)) {
            posix_kill(-$run->runner_pid, SIGKILL);
        }

        foreach (File::isDirectory($root.'/fake') ? workflowEndToEndFakePids($root) : [] as $pid) {
            if (workflowEndToEndProcessIsAlive($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }

        File::deleteDirectory($root);
    }
});
