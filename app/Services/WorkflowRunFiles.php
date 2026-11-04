<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunModel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads the files of a workflow run (`<root>/workflow-runs/<slug>/`) for the runner and the UI.
 *
 * The worker writes these files. Every read is size-bounded, stays inside the verified run directory and never
 * follows a symbolic link; every path segment taken from a request is checked against a strict pattern first.
 * Nothing returned here contains an absolute path, except demoPath(), which the preview controller serves.
 */
class WorkflowRunFiles
{
    public const STATE_SCHEMA = 'benchforge-workflow-state/1';

    public const RESULT_SCHEMA = 'benchforge-workflow-result/1';

    public const RUN_SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{2,80}$/';

    public const MODEL_SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public const STEP_ID_PATTERN = '/^[a-z][a-zA-Z0-9_]{0,39}$/';

    public const ITEM_KEY_PATTERN = '/^[0-9]{3,6}$/';

    private const RUNS_DIRECTORY = 'workflow-runs';

    private const MAX_JSON_BYTES = 2_097_152;

    private const MAX_ITEM_BYTES = 262_144;

    private const EVENTS_TAIL_BYTES = 65_536;

    private const DETAIL_TEXT_BYTES = 204_800;

    private const ATTEMPT_DIRECTORY_PATTERN = '/^attempt-([1-9][0-9]{0,2})$/';

    private const ATTEMPT_FILE_PATTERN = '/^attempt-[1-9][0-9]{0,2}\/[a-z][a-z0-9_-]{0,39}\.(?:txt|json|html)$/';

    private const RESULT_STATUSES = ['completed', 'failed', 'stopped', 'skipped'];

    private const STEP_STATUSES = ['pending', 'running', 'completed', 'partial', 'failed', 'stopped', 'skipped'];

    private const ATTEMPT_KINDS = ['initial', 'retry', 'repair', 'transient'];

    private const EVENT_LEVELS = ['info', 'warn', 'error'];

    private const CODE_STEP_TYPES = ['code_text', 'code_agent'];

    /**
     * `<root>/workflow-runs`, created on first use (0700). It must be a real directory, never a symbolic link.
     */
    public function runsRoot(): string
    {
        $root = $this->dataRoot();

        if ($root === null) {
            throw new BenchmarkRunnerException('Veri kökü bulunamadı.');
        }

        $directory = $root.DIRECTORY_SEPARATOR.self::RUNS_DIRECTORY;

        if (is_link($directory)) {
            throw new BenchmarkRunnerException('İş akışı çalıştırma klasörü sembolik bağlantı olamaz.');
        }

        if (! file_exists($directory)) {
            File::ensureDirectoryExists($directory, 0700, false);
        }

        if (! $this->isCanonicalDirectory($directory)) {
            throw new BenchmarkRunnerException('İş akışı çalıştırma klasörü kullanılamıyor.');
        }

        return $directory;
    }

    /**
     * The run's stored directory when it is exactly `<runs root>/<slug>`, canonical and not a symbolic link.
     */
    public function runDirectory(WorkflowRun $run): ?string
    {
        $root = $this->existingRunsRoot();
        $storedPath = $run->working_directory;

        if ($root === null
            || ! is_string($storedPath)
            || $storedPath === ''
            || ! is_string($run->slug)
            || preg_match(self::RUN_SLUG_PATTERN, $run->slug) !== 1
            || is_link($storedPath)) {
            return null;
        }

        $realPath = realpath($storedPath);

        if ($realPath === false
            || $realPath !== $storedPath
            || dirname($realPath) !== $root
            || basename($realPath) !== $run->slug
            || ! is_dir($realPath)) {
            return null;
        }

        return $realPath;
    }

    /**
     * `state.json` when it is at most 2 MiB, has the state schema and belongs to this run.
     *
     * @return array<string, mixed>|null
     */
    public function readState(WorkflowRun $run): ?array
    {
        $directory = $this->runDirectory($run);

        if ($directory === null) {
            return null;
        }

        $state = $this->readJson($directory, $directory.'/state.json', self::MAX_JSON_BYTES);

        if (! is_array($state)
            || ($state['schema'] ?? null) !== self::STATE_SCHEMA
            || ($state['runId'] ?? null) !== $run->slug) {
            return null;
        }

        return $state;
    }

    /**
     * The template snapshot (`template.json`) the run was started with.
     *
     * @return array<string, mixed>|null
     */
    public function template(WorkflowRun $run): ?array
    {
        $directory = $this->runDirectory($run);

        if ($directory === null) {
            return null;
        }

        $template = $this->readJson($directory, $directory.'/template.json', WorkflowTemplateValidator::MAX_TEMPLATE_BYTES);

        if (! is_array($template)
            || ($template['schema'] ?? null) !== WorkflowTemplateValidator::SCHEMA
            || ! is_array($template['steps'] ?? null)
            || ! array_is_list($template['steps'])) {
            return null;
        }

        return $template;
    }

    /**
     * The newest events, oldest first, parsed from the last 64 KiB of `events.jsonl`. When the log was just rotated,
     * the rest of that budget is read from the end of `events.1.jsonl`.
     *
     * @return list<array{time: ?string, level: string, type: string, model: ?string, step: ?string, item: ?string, message: string}>
     */
    public function eventsTail(WorkflowRun $run, int $limit = 80): array
    {
        $directory = $this->runDirectory($run);

        if ($directory === null) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $budget = self::EVENTS_TAIL_BYTES;
        $events = [];

        foreach (['events.jsonl', 'events.1.jsonl'] as $name) {
            if ($budget <= 0 || count($events) >= $limit) {
                break;
            }

            $tail = $this->readTail($directory, $directory.'/'.$name, $budget);

            if ($tail === null) {
                continue;
            }

            $budget -= strlen($tail['content']);
            $content = $tail['content'];

            if ($tail['partial']) {
                $newline = strpos($content, "\n");
                $content = $newline === false ? '' : substr($content, $newline + 1);
            }

            $events = [...$this->parseEvents($content), ...$events];
        }

        return array_slice($events, -$limit);
    }

    /**
     * The detail of one top-level step, or of one foreach child for one item (§12.2). Prompts, responses and text output
     * are clipped at 200 KB. Files that do not exist yet give null fields; an unknown model, step or item gives null.
     *
     * @return array<string, mixed>|null
     */
    public function stepDetail(WorkflowRun $run, string $modelSlug, string $stepId, ?string $itemKey = null, ?string $childId = null): ?array
    {
        if (preg_match(self::MODEL_SLUG_PATTERN, $modelSlug) !== 1
            || preg_match(self::STEP_ID_PATTERN, $stepId) !== 1
            || ($itemKey === null) !== ($childId === null)
            || ($itemKey !== null && preg_match(self::ITEM_KEY_PATTERN, $itemKey) !== 1)
            || ($childId !== null && preg_match(self::STEP_ID_PATTERN, $childId) !== 1)) {
            return null;
        }

        $directory = $this->runDirectory($run);
        $runModel = $run->models()->where('slug', $modelSlug)->first();
        $template = $this->template($run);

        if ($directory === null || ! $runModel instanceof WorkflowRunModel || $template === null) {
            return null;
        }

        $step = $this->findStep($template['steps'], $stepId);

        if ($step === null) {
            return null;
        }

        $state = $this->readState($run);
        $modelState = is_array($state['models'][$modelSlug] ?? null) ? $state['models'][$modelSlug] : [];
        $stepState = is_array($modelState['steps'][$stepId] ?? null) ? $modelState['steps'][$stepId] : [];
        $stepDirectory = $directory.'/models/'.$modelSlug.'/steps/'.$stepId;
        $item = null;
        $definition = $step;
        $stateStatus = $this->allowed($stepState['status'] ?? null, self::STEP_STATUSES);
        $stateDetail = $stepState;

        if ($itemKey !== null && $childId !== null) {
            $child = ($step['type'] ?? null) === 'foreach' && is_array($step['steps'] ?? null)
                ? $this->findStep($step['steps'], $childId)
                : null;
            $itemRecord = $child === null ? null : $this->itemRecord($stepDirectory, $directory, $itemKey);

            if ($child === null || $itemRecord === null) {
                return null;
            }

            $definition = $child;
            $item = ['key' => $itemKey, 'label' => $itemRecord['label']];
            $itemState = $this->stateItem($stepState, $itemKey);
            $stateStatus = $this->allowed($itemState['steps'][$childId] ?? null, self::STEP_STATUSES);
            $stateDetail = [];
            $stepDirectory .= '/items/'.$itemKey.'/'.$childId;
        }

        $type = (string) ($definition['type'] ?? '');
        $result = $this->readResult($directory, $stepDirectory, (string) $definition['id']);
        [$output, $outputTruncated] = $result === null ? [null, false] : $this->output($type, $result['output'] ?? null);

        return [
            'model' => ['slug' => $runModel->slug, 'name' => $runModel->model_name],
            'step' => ['id' => (string) $definition['id'], 'type' => $type, 'title' => $this->title($definition)],
            'item' => $item,
            'status' => $result === null
                ? ($stateStatus ?? 'pending')
                : ($this->allowed($result['status'] ?? null, self::RESULT_STATUSES) ?? 'failed'),
            'message' => $this->text(($result ?? $stateDetail)['message'] ?? null, 2000),
            'startedAt' => $this->text(($result ?? $stateDetail)['startedAt'] ?? null, 40),
            'finishedAt' => $this->text(($result ?? $stateDetail)['finishedAt'] ?? null, 40),
            'durationSeconds' => $this->duration(($result ?? $stateDetail)['durationSeconds'] ?? null),
            'usage' => $this->usage(($result ?? $stateDetail)['usage'] ?? null),
            'attempts' => $result === null
                ? $this->discoveredAttempts($directory, $stepDirectory)
                : $this->attempts($directory, $stepDirectory, $result['attempts'] ?? null),
            'output' => $output,
            'outputTruncated' => $outputTruncated,
            'demoUrl' => in_array($type, self::CODE_STEP_TYPES, true)
                && $this->demoPath($run, $modelSlug, $stepId, $itemKey, $childId) !== null
                ? $this->demoUrl($run, $modelSlug, $stepId, $itemKey, $childId)
                : null,
        ];
    }

    /**
     * One card per model and gallery item (§12.3), built from the template's `gallery`, each model's `items.json`,
     * the `item.json` of every item (the worker writes `galleryTitle` there) and the result of the gallery file step.
     * A failed file step has no output, so its card takes the static checks of its last checked attempt and the
     * step's message, which together explain why there is no demo.
     *
     * @return list<array{model: string, modelName: string, step: string, child: string, item: string, title: string, status: string, message: ?string, checks: ?array{critical: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>}, bytes: ?int, demoUrl: ?string}>
     */
    public function galleryItems(WorkflowRun $run): array
    {
        $template = $this->template($run);
        $directory = $this->runDirectory($run);
        $gallery = is_array($template['gallery'] ?? null) ? $template['gallery'] : null;

        if ($template === null || $directory === null || $gallery === null) {
            return [];
        }

        $stepId = $gallery['from'] ?? null;
        $childId = $gallery['file'] ?? null;
        $step = is_string($stepId) ? $this->findStep($template['steps'], $stepId) : null;
        $child = is_string($childId) && ($step['type'] ?? null) === 'foreach' && is_array($step['steps'] ?? null)
            ? $this->findStep($step['steps'], $childId)
            : null;

        if (! is_string($stepId)
            || ! is_string($childId)
            || $child === null
            || preg_match(self::STEP_ID_PATTERN, $stepId) !== 1
            || preg_match(self::STEP_ID_PATTERN, $childId) !== 1) {
            return [];
        }

        $cards = [];

        foreach ($run->models as $model) {
            if (preg_match(self::MODEL_SLUG_PATTERN, (string) $model->slug) !== 1) {
                continue;
            }

            $foreachDirectory = $directory.'/models/'.$model->slug.'/steps/'.$stepId;
            $entries = $this->readJson($directory, $foreachDirectory.'/items.json', self::MAX_JSON_BYTES);

            if (! is_array($entries) || ! array_is_list($entries)) {
                continue;
            }

            foreach ($entries as $position => $entry) {
                $key = is_array($entry) ? ($entry['key'] ?? null) : null;

                if (! is_string($key) || preg_match(self::ITEM_KEY_PATTERN, $key) !== 1) {
                    continue;
                }

                $itemJson = $this->readJson($directory, $foreachDirectory.'/items/'.$key.'/item.json', self::MAX_ITEM_BYTES);
                $itemJson = is_array($itemJson) ? $itemJson : [];
                $childDirectory = $foreachDirectory.'/items/'.$key.'/'.$childId;
                $result = $this->readResult($directory, $childDirectory, $childId);
                $output = is_array($result['output'] ?? null) ? $result['output'] : [];
                $hasDemo = $this->demoPath($run, $model->slug, $stepId, $key, $childId) !== null;

                $cards[] = [
                    'model' => $model->slug,
                    'modelName' => $model->model_name,
                    'step' => $stepId,
                    'child' => $childId,
                    'item' => $key,
                    'title' => $this->text($itemJson['galleryTitle'] ?? null, 120)
                        ?? $this->text($entry['label'] ?? null, 120)
                        ?? $this->text($itemJson['label'] ?? null, 120)
                        ?? 'Öğe '.($position + 1),
                    'status' => $this->allowed($result['status'] ?? null, self::RESULT_STATUSES)
                        ?? $this->allowed($itemJson['status'] ?? null, self::STEP_STATUSES)
                        ?? 'pending',
                    'message' => $this->text($result['message'] ?? null, 500),
                    'checks' => $this->checks($output['checks'] ?? null)
                        ?? $this->lastAttemptChecks($directory, $childDirectory, $result['attempts'] ?? null),
                    'bytes' => $this->nonNegativeInteger($output['bytes'] ?? null),
                    'demoUrl' => $hasDemo ? $this->demoUrl($run, $model->slug, $stepId, $key, $childId) : null,
                ];
            }
        }

        return $cards;
    }

    /**
     * The real path of `models/<model>/steps/<step>/items/<item>/<child>/demo.html`, or, without an item and child, of
     * `models/<model>/steps/<step>/demo.html` for a top-level code step of the run's template snapshot, when it is a
     * regular file inside the run directory and no part of the path is a symbolic link.
     */
    public function demoPath(WorkflowRun $run, string $modelSlug, string $stepId, ?string $itemKey = null, ?string $childId = null): ?string
    {
        if (preg_match(self::MODEL_SLUG_PATTERN, $modelSlug) !== 1
            || preg_match(self::STEP_ID_PATTERN, $stepId) !== 1
            || ($itemKey === null) !== ($childId === null)
            || ($itemKey !== null && preg_match(self::ITEM_KEY_PATTERN, $itemKey) !== 1)
            || ($childId !== null && preg_match(self::STEP_ID_PATTERN, $childId) !== 1)) {
            return null;
        }

        $directory = $this->runDirectory($run);

        if ($directory === null || ($itemKey === null && ! $this->isTopLevelCodeStep($run, $stepId))) {
            return null;
        }

        $path = $directory.'/models/'.$modelSlug.'/steps/'.$stepId
            .($itemKey === null ? '' : '/items/'.$itemKey.'/'.$childId).'/demo.html';

        if (is_link($path) || ! is_file($path)) {
            return null;
        }

        $realPath = realpath($path);

        return $realPath === $path && $this->isWithin($directory, $realPath) ? $realPath : null;
    }

    private function isTopLevelCodeStep(WorkflowRun $run, string $stepId): bool
    {
        $template = $this->template($run);
        $step = $template === null ? null : $this->findStep($template['steps'], $stepId);

        return in_array($step['type'] ?? null, self::CODE_STEP_TYPES, true);
    }

    /**
     * @param  list<mixed>  $steps
     * @return array<string, mixed>|null
     */
    private function findStep(array $steps, string $stepId): ?array
    {
        foreach ($steps as $step) {
            if (is_array($step) && ($step['id'] ?? null) === $stepId) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The item's label from `item.json`, or from its entry in the foreach's `items.json`; null when the item does not exist.
     *
     * @return array{label: ?string}|null
     */
    private function itemRecord(string $foreachDirectory, string $directory, string $itemKey): ?array
    {
        $itemJson = $this->readJson($directory, $foreachDirectory.'/items/'.$itemKey.'/item.json', self::MAX_ITEM_BYTES);

        if (is_array($itemJson) && ($itemJson['key'] ?? null) === $itemKey) {
            return ['label' => $this->text($itemJson['label'] ?? null, 120)];
        }

        $entries = $this->readJson($directory, $foreachDirectory.'/items.json', self::MAX_JSON_BYTES);

        foreach (is_array($entries) && array_is_list($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && ($entry['key'] ?? null) === $itemKey) {
                return ['label' => $this->text($entry['label'] ?? null, 120)];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $stepState
     * @return array<string, mixed>
     */
    private function stateItem(array $stepState, string $itemKey): array
    {
        foreach (is_array($stepState['items'] ?? null) ? $stepState['items'] : [] as $item) {
            if (is_array($item) && ($item['key'] ?? null) === $itemKey) {
                return $item;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readResult(string $directory, string $stepDirectory, string $stepId): ?array
    {
        $result = $this->readJson($directory, $stepDirectory.'/result.json', self::MAX_JSON_BYTES);

        if (! is_array($result)
            || ($result['schema'] ?? null) !== self::RESULT_SCHEMA
            || ($result['stepId'] ?? null) !== $stepId) {
            return null;
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attempts(string $directory, string $stepDirectory, mixed $attempts): array
    {
        if (! is_array($attempts) || ! array_is_list($attempts)) {
            return [];
        }

        $details = [];

        foreach (array_slice($attempts, 0, 50) as $position => $attempt) {
            if (! is_array($attempt)) {
                continue;
            }

            $files = is_array($attempt['files'] ?? null) ? $attempt['files'] : [];
            [$prompt, $promptTruncated] = $this->attemptText($directory, $stepDirectory, $files['prompt'] ?? null);
            [$response, $responseTruncated] = $this->attemptText($directory, $stepDirectory, $files['response'] ?? null);

            $details[] = [
                'n' => $this->nonNegativeInteger($attempt['n'] ?? null) ?? $position + 1,
                'kind' => $this->allowed($attempt['kind'] ?? null, self::ATTEMPT_KINDS),
                'status' => $this->allowed($attempt['status'] ?? null, self::RESULT_STATUSES),
                'error' => $this->text($attempt['error'] ?? null, 2000),
                'durationSeconds' => $this->duration($attempt['durationSeconds'] ?? null),
                'usage' => $this->usage($attempt['usage'] ?? null),
                'prompt' => $prompt,
                'promptTruncated' => $promptTruncated,
                'response' => $response,
                'responseTruncated' => $responseTruncated,
            ];
        }

        return $details;
    }

    /**
     * Attempts of a step that has no result yet, found from its `attempt-<k>` folders.
     *
     * @return list<array<string, mixed>>
     */
    private function discoveredAttempts(string $directory, string $stepDirectory): array
    {
        if (! $this->isWithin($directory, $stepDirectory) || ! $this->isCanonicalDirectory($stepDirectory)) {
            return [];
        }

        $numbers = [];

        foreach (scandir($stepDirectory) ?: [] as $name) {
            if (preg_match(self::ATTEMPT_DIRECTORY_PATTERN, $name, $matches) === 1
                && $this->isCanonicalDirectory($stepDirectory.'/'.$name)) {
                $numbers[] = (int) $matches[1];
            }
        }

        sort($numbers);

        return array_map(function (int $number) use ($directory, $stepDirectory): array {
            [$prompt, $promptTruncated] = $this->attemptText($directory, $stepDirectory, "attempt-{$number}/prompt.txt");
            [$response, $responseTruncated] = $this->attemptText($directory, $stepDirectory, "attempt-{$number}/response.txt");

            return [
                'n' => $number,
                'kind' => null,
                'status' => null,
                'error' => null,
                'durationSeconds' => null,
                'usage' => $this->usage(null),
                'prompt' => $prompt,
                'promptTruncated' => $promptTruncated,
                'response' => $response,
                'responseTruncated' => $responseTruncated,
            ];
        }, array_slice($numbers, 0, 50));
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function attemptText(string $directory, string $stepDirectory, mixed $relativePath): array
    {
        if (! is_string($relativePath) || preg_match(self::ATTEMPT_FILE_PATTERN, $relativePath) !== 1) {
            return [null, false];
        }

        $clipped = $this->readClipped($directory, $stepDirectory.'/'.$relativePath, self::DETAIL_TEXT_BYTES);

        return $clipped === null ? [null, false] : [$clipped['text'], $clipped['truncated']];
    }

    /**
     * @return array{0: mixed, 1: bool}
     */
    private function output(string $type, mixed $output): array
    {
        if ($type === 'llm_text') {
            return is_string($output) ? $this->clipText($output) : [null, false];
        }

        if ($type === 'llm_json') {
            $encoded = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($encoded === false) {
                return [null, false];
            }

            return strlen($encoded) > self::DETAIL_TEXT_BYTES ? $this->clipText($encoded) : [$output, false];
        }

        if (in_array($type, self::CODE_STEP_TYPES, true) && is_array($output)) {
            $file = $output['file'] ?? null;

            return [[
                'file' => is_string($file) && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}\.html$/', $file) === 1 ? $file : null,
                'bytes' => $this->nonNegativeInteger($output['bytes'] ?? null),
                'checks' => $this->checks($output['checks'] ?? null),
            ], false];
        }

        return [null, false];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function clipText(string $text): array
    {
        $text = mb_scrub($text, 'UTF-8');

        if (strlen($text) <= self::DETAIL_TEXT_BYTES) {
            return [$text, false];
        }

        return [mb_strcut($text, 0, self::DETAIL_TEXT_BYTES, 'UTF-8'), true];
    }

    /**
     * @return array{critical: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>}|null
     */
    private function checks(mixed $checks): ?array
    {
        if (! is_array($checks) || (! array_key_exists('critical', $checks) && ! array_key_exists('warnings', $checks))) {
            return null;
        }

        $normalized = [];

        foreach (['critical', 'warnings'] as $group) {
            $normalized[$group] = [];
            $entries = is_array($checks[$group] ?? null) && array_is_list($checks[$group]) ? $checks[$group] : [];

            foreach (array_slice($entries, 0, 50) as $entry) {
                $code = is_array($entry) ? $this->text($entry['code'] ?? null, 64) : null;
                $message = is_array($entry) ? $this->text($entry['message'] ?? null, 500) : $this->text($entry, 500);

                if ($message !== null) {
                    $normalized[$group][] = ['code' => $code ?? 'check', 'message' => $message];
                }
            }
        }

        return $normalized;
    }

    /**
     * `checks.json` of the last attempt that recorded one, read from inside the step folder; null when there is none.
     *
     * @return array{critical: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>}|null
     */
    private function lastAttemptChecks(string $directory, string $stepDirectory, mixed $attempts): ?array
    {
        if (! is_array($attempts) || ! array_is_list($attempts)) {
            return null;
        }

        foreach (array_reverse(array_slice($attempts, -50)) as $attempt) {
            $path = is_array($attempt) && is_array($attempt['files'] ?? null) ? ($attempt['files']['checks'] ?? null) : null;

            if (is_string($path)) {
                return preg_match(self::ATTEMPT_FILE_PATTERN, $path) === 1 && str_ends_with($path, '.json')
                    ? $this->checks($this->readJson($directory, $stepDirectory.'/'.$path, self::MAX_ITEM_BYTES))
                    : null;
            }
        }

        return null;
    }

    /**
     * @return array{inputTokens: ?int, outputTokens: ?int, costUsd: int|float|null, calls: int}
     */
    private function usage(mixed $usage): array
    {
        $usage = is_array($usage) ? $usage : [];
        $cost = $usage['costUsd'] ?? null;

        return [
            'inputTokens' => $this->nonNegativeInteger($usage['inputTokens'] ?? null),
            'outputTokens' => $this->nonNegativeInteger($usage['outputTokens'] ?? null),
            'costUsd' => (is_int($cost) || is_float($cost)) && is_finite((float) $cost) && $cost >= 0 ? $cost : null,
            'calls' => $this->nonNegativeInteger($usage['calls'] ?? null) ?? 0,
        ];
    }

    /**
     * Valid event objects of a JSON lines text, in file order; blank, broken and non-object lines are skipped.
     *
     * @return list<array{time: ?string, level: string, type: string, model: ?string, step: ?string, item: ?string, message: string}>
     */
    private function parseEvents(string $content): array
    {
        $events = [];

        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            try {
                $event = $this->event(json_decode($line, true, 32, JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                continue;
            }

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return array{time: ?string, level: string, type: string, model: ?string, step: ?string, item: ?string, message: string}|null
     */
    private function event(mixed $event): ?array
    {
        if (! is_array($event) || array_is_list($event)) {
            return null;
        }

        $type = $this->text($event['type'] ?? null, 40);

        if ($type === null) {
            return null;
        }

        return [
            'time' => $this->text($event['time'] ?? null, 40),
            'level' => $this->allowed($event['level'] ?? null, self::EVENT_LEVELS) ?? 'info',
            'type' => $type,
            'model' => $this->text($event['model'] ?? null, 64),
            'step' => $this->text($event['step'] ?? null, 40),
            'item' => $this->text($event['item'] ?? null, 16),
            'message' => $this->text($event['message'] ?? null, 1000) ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function title(array $step): string
    {
        return $this->text($step['title'] ?? null, 120) ?? (string) ($step['id'] ?? '');
    }

    private function demoUrl(WorkflowRun $run, string $modelSlug, string $stepId, ?string $itemKey, ?string $childId): string
    {
        if ($itemKey === null || $childId === null) {
            return route('workflows.runs.demos.step', ['workflowRun' => $run, 'model' => $modelSlug, 'step' => $stepId], absolute: false);
        }

        return route('workflows.runs.demos.show', [
            'workflowRun' => $run,
            'model' => $modelSlug,
            'step' => $stepId,
            'item' => $itemKey,
            'child' => $childId,
        ], absolute: false);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowed(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(mb_scrub($value, 'UTF-8'));

        return $value === '' ? null : Str::limit($value, $limit, '…');
    }

    private function duration(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 31_536_000
            ? round((float) $value, 2)
            : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function readJson(string $directory, string $path, int $maximumBytes): ?array
    {
        $content = $this->readPrivateFile($directory, $path, $maximumBytes);

        if ($content === null) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function readPrivateFile(string $directory, string $path, int $maximumBytes): ?string
    {
        $realPath = $this->safeFile($directory, $path);

        if ($realPath === null) {
            return null;
        }

        try {
            if (File::size($realPath) > $maximumBytes) {
                return null;
            }

            return File::get($realPath);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The first $maximumBytes of a text file, valid UTF-8, and whether the file was longer.
     *
     * @return array{text: string, truncated: bool}|null
     */
    private function readClipped(string $directory, string $path, int $maximumBytes): ?array
    {
        $realPath = $this->safeFile($directory, $path);

        if ($realPath === null) {
            return null;
        }

        try {
            $size = File::size($realPath);
            $handle = fopen($realPath, 'rb');

            if ($handle === false) {
                return null;
            }

            try {
                $content = $size > 0 ? fread($handle, min($size, $maximumBytes)) : '';
            } finally {
                fclose($handle);
            }

            if ($content === false) {
                return null;
            }

            $content = $size > $maximumBytes ? mb_strcut($content, 0, $maximumBytes, 'UTF-8') : $content;

            return ['text' => mb_scrub($content, 'UTF-8'), 'truncated' => $size > $maximumBytes];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The last $maximumBytes of a file, and whether it starts in the middle of the file.
     *
     * @return array{content: string, partial: bool}|null
     */
    private function readTail(string $directory, string $path, int $maximumBytes): ?array
    {
        $realPath = $this->safeFile($directory, $path);

        if ($realPath === null) {
            return null;
        }

        try {
            $size = File::size($realPath);
            $offset = max(0, $size - $maximumBytes);
            $handle = fopen($realPath, 'rb');

            if ($handle === false) {
                return null;
            }

            try {
                if ($offset > 0 && fseek($handle, $offset) !== 0) {
                    return null;
                }

                $content = $size - $offset > 0 ? fread($handle, $size - $offset) : '';
            } finally {
                fclose($handle);
            }

            return $content === false ? null : ['content' => $content, 'partial' => $offset > 0];
        } catch (Throwable) {
            return null;
        }
    }

    private function safeFile(string $directory, string $path): ?string
    {
        if ($path === '' || ! $this->isWithin($directory, $path) || is_link($path) || ! File::isFile($path)) {
            return null;
        }

        $realPath = realpath($path);

        return $realPath !== false && $realPath === $path && $this->isWithin($directory, $realPath) ? $realPath : null;
    }

    private function existingRunsRoot(): ?string
    {
        $root = $this->dataRoot();

        if ($root === null) {
            return null;
        }

        $directory = $root.DIRECTORY_SEPARATOR.self::RUNS_DIRECTORY;

        return $this->isCanonicalDirectory($directory) ? $directory : null;
    }

    private function dataRoot(): ?string
    {
        $configured = rtrim((string) config('benchforge.root_path'), DIRECTORY_SEPARATOR);
        $root = $configured === '' ? false : realpath($configured);

        return $root === false || $root === DIRECTORY_SEPARATOR || ! is_dir($root) ? null : $root;
    }

    private function isCanonicalDirectory(string $path): bool
    {
        return ! is_link($path) && realpath($path) === $path && is_dir($path);
    }

    private function isWithin(string $parent, string $candidate): bool
    {
        $normalizedParent = rtrim($parent, DIRECTORY_SEPARATOR);

        return $candidate === $normalizedParent
            || str_starts_with($candidate, $normalizedParent.DIRECTORY_SEPARATOR);
    }
}
