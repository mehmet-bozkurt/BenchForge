<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Turns a model choice (a saved group, OpenRouter catalog models or OpenCode Go catalog models) into a run selection.
 * Benchmarks and workflows share it. The data root is read on every call, because saved settings can move it mid-request.
 *
 * Every selection has the keys `absolutePath`, `reference`, `configContents`, `openCodeConfigContents` and `option`
 * that the benchmark runner has always used, plus `models`: one entry per model with its provider, catalog id,
 * provider-prefixed OpenCode model, folder, port and OpenRouter routing (null when unknown or not OpenRouter).
 */
class ModelSelection
{
    public const BENCHMARK_MODEL_LIMIT = 16;

    public const WORKFLOW_MODEL_LIMIT = 5;

    public const MODES = ['group', 'custom', 'opencode_go'];

    public const WORKFLOW_PROVIDERS = ['openrouter', 'opencode-go'];

    private const CATALOG_PORT_START = 3251;

    public function __construct(
        private OpenRouterCatalog $openRouterCatalog,
        private OpenRouterProviderRouting $openRouterProviderRouting,
        private OpenCodeGoCatalog $openCodeGoCatalog,
    ) {}

    /**
     * Every saved group in the data root's `config/` directory. One invalid group file makes the whole listing fail.
     *
     * @return list<array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}>
     */
    public function groupOptions(): array
    {
        return collect($this->listFiles($this->projectRoot().'/config', '.conf'))
            ->map(fn (string $filename): array => $this->parseConfig($filename)['option'])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     openRouterModels: Collection<int, OpenRouterModel>,
     *     openCodeGoModels: Collection<int, OpenCodeGoModel>,
     *     catalog: array{count: int, lastSyncedAt: ?CarbonImmutable},
     *     goCatalog: array{count: int, lastSyncedAt: ?CarbonImmutable}
     * }
     */
    public function catalogOptions(): array
    {
        return [
            'openRouterModels' => $this->openRouterCatalog->selectableModels(),
            'openCodeGoModels' => $this->openCodeGoCatalog->selectableModels(),
            'catalog' => [
                'count' => OpenRouterModel::query()->where('available', true)->count(),
                'lastSyncedAt' => $this->openRouterCatalog->lastSyncedAt(),
            ],
            'goCatalog' => [
                'count' => OpenCodeGoModel::query()->where('available', true)->count(),
                'lastSyncedAt' => $this->openCodeGoCatalog->lastSyncedAt(),
            ],
        ];
    }

    /**
     * Resolves `model_mode` with `config` (group), `models`, `routing_mode` and `fixed_providers` (custom) or `go_models`
     * (opencode_go). An unknown or missing mode is a group. Benchmarks have never capped groups, so a group only has to
     * fit a limit that is stricter than the benchmark limit.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array{value: string}>|null  $groupOptions  null checks the selected group against the group file names only
     * @return array{absolutePath: ?string, reference: string, configContents: ?string, openCodeConfigContents: ?string, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}, models: list<array{provider: string, modelId: string, model: string, folder: string, port: int, routing: ?array<string, mixed>}>}
     */
    public function resolve(array $input, int $modelLimit = self::BENCHMARK_MODEL_LIMIT, bool $refreshProviders = false, ?array $groupOptions = null): array
    {
        return match ($input['model_mode'] ?? 'group') {
            'custom' => $this->openRouter($input['models'] ?? [], $input['routing_mode'] ?? 'auto', $input['fixed_providers'] ?? [], $refreshProviders, $modelLimit),
            'opencode_go' => $this->openCodeGo($input['go_models'] ?? [], $modelLimit),
            default => $this->group(
                (string) ($input['config'] ?? ''),
                $groupOptions,
                $modelLimit < self::BENCHMARK_MODEL_LIMIT ? $modelLimit : null,
            ),
        };
    }

    /**
     * Resolves a workflow's model choice: at most WORKFLOW_MODEL_LIMIT models, each model once, OpenRouter and
     * OpenCode Go only. OpenRouter models from a group get the automatic provider routing of a catalog choice.
     *
     * @param  array<string, mixed>  $input
     * @return array{absolutePath: ?string, reference: string, configContents: ?string, openCodeConfigContents: ?string, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}, models: list<array{provider: string, modelId: string, model: string, folder: string, port: int, routing: ?array<string, mixed>}>}
     */
    public function resolveForWorkflow(array $input, bool $refreshProviders = true): array
    {
        $selection = $this->resolve($input, self::WORKFLOW_MODEL_LIMIT, $refreshProviders);
        $seenModels = [];

        foreach ($selection['models'] as $model) {
            if (! in_array($model['provider'], self::WORKFLOW_PROVIDERS, true)) {
                throw new BenchmarkRunnerException("Bu gruptaki {$model['model']} modeli iş akışlarında desteklenmiyor (yalnız OpenRouter ve OpenCode Go).");
            }

            if (in_array($model['model'], $seenModels, true)) {
                throw new BenchmarkRunnerException("Bu grupta {$model['model']} modeli birden fazla kez var.");
            }

            $seenModels[] = $model['model'];
        }

        $unroutedModels = collect($selection['models'])
            ->filter(fn (array $model): bool => $model['provider'] === 'openrouter' && $model['routing'] === null)
            ->pluck('modelId')
            ->values()
            ->all();

        if ($unroutedModels === []) {
            return $selection;
        }

        $routing = $this->openRouterProviderRouting->routingForModels($unroutedModels, 'auto');

        foreach ($selection['models'] as $index => $model) {
            if ($model['provider'] === 'openrouter' && $model['routing'] === null) {
                $selection['models'][$index]['routing'] = $routing[$model['modelId']];
            }
        }

        return $selection;
    }

    /**
     * A saved group. The group must be one of $groupOptions (or, when null, an existing group file).
     *
     * @param  list<array{value: string}>|null  $groupOptions
     * @return array{absolutePath: string, reference: string, configContents: null, openCodeConfigContents: null, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}, models: list<array{provider: string, modelId: string, model: string, folder: string, port: int, routing: null}>}
     */
    public function group(string $selected, ?array $groupOptions = null, ?int $modelLimit = null): array
    {
        $groupOptions ??= array_map(
            fn (string $filename): array => ['value' => 'config/'.$filename],
            $this->listFiles($this->projectRoot().'/config', '.conf'),
        );

        if (! collect($groupOptions)->contains('value', $selected)) {
            throw new BenchmarkRunnerException('Listeden geçerli bir model grubu seçin.');
        }

        $selection = $this->parseConfig(basename($selected));
        $modelCount = $selection['option']['modelCount'];

        if ($modelLimit !== null && $modelCount > $modelLimit) {
            throw new BenchmarkRunnerException("Bu grupta {$modelCount} model var; iş akışında en fazla {$modelLimit} model seçilebilir.");
        }

        return $selection;
    }

    /**
     * OpenRouter catalog models with the chosen provider routing. Fixed routing checks each provider against the endpoint list.
     *
     * @param  list<string>  $selectedModels
     * @param  array<string, mixed>  $fixedProviders
     * @return array{absolutePath: null, reference: string, configContents: string, openCodeConfigContents: string, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}, models: list<array{provider: string, modelId: string, model: string, folder: string, port: int, routing: array<string, mixed>}>}
     */
    public function openRouter(array $selectedModels, string $routingMode = 'auto', array $fixedProviders = [], bool $refreshProviders = false, int $modelLimit = self::BENCHMARK_MODEL_LIMIT): array
    {
        $selectedModels = $this->normalizeModels($selectedModels);

        if ($selectedModels === [] || count($selectedModels) > $modelLimit) {
            throw new BenchmarkRunnerException('OpenRouter kataloğundan 1–'.$modelLimit.' model seçin.');
        }

        $modelsById = OpenRouterModel::query()
            ->whereIn('model_id', $selectedModels)
            ->where('available', true)
            ->where('agent_compatible', true)
            ->get()
            ->keyBy('model_id');

        if ($modelsById->count() !== count($selectedModels)) {
            throw new BenchmarkRunnerException('Seçilen modellerden biri artık kullanılamıyor veya araç desteği sunmuyor. Kataloğu yenileyin.');
        }

        $routing = $this->openRouterProviderRouting->routingForModels($selectedModels, $routingMode, $fixedProviders, $refreshProviders);

        $ports = [];
        $models = [];
        $configLines = [
            '# BenchForge tarafından OpenRouter kataloğundan oluşturuldu.',
            '# max_parallel=1',
            '# openrouter_routing='.$routingMode,
        ];

        if ($routingMode === 'fixed') {
            $configLines[] = '# openrouter_requested_providers='.implode(', ', array_map(fn (string $modelId): string => $modelId.':'.$fixedProviders[$modelId], $selectedModels));
        }
        $openCodeModels = [];

        foreach ($selectedModels as $index => $modelId) {
            $port = self::CATALOG_PORT_START + $index;
            $ports[] = $port;
            $folder = self::folderFor('openrouter/'.$modelId);
            $configLines[] = "{$folder}|openrouter/{$modelId}|{$port}";
            $openCodeModels[$modelId] = [
                'options' => [
                    'provider' => $routing[$modelId],
                ],
            ];
            $models[] = [
                'provider' => 'openrouter',
                'modelId' => $modelId,
                'model' => 'openrouter/'.$modelId,
                'folder' => $folder,
                'port' => $port,
                'routing' => $routing[$modelId],
            ];
        }

        $openCodeConfig = [
            '$schema' => 'https://opencode.ai/config.json',
            'provider' => [
                'openrouter' => [
                    'models' => $openCodeModels,
                ],
            ],
        ];

        return [
            'absolutePath' => null,
            'reference' => 'custom:openrouter',
            'configContents' => implode(PHP_EOL, $configLines).PHP_EOL,
            'openCodeConfigContents' => json_encode(
                $openCodeConfig,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ).PHP_EOL,
            'option' => [
                'value' => 'custom:openrouter',
                'label' => 'Özel OpenRouter seçimi',
                'modelCount' => count($selectedModels),
                'ports' => $ports,
                'providers' => ['openrouter'],
                'maxParallel' => 1,
            ],
            'models' => $models,
        ];
    }

    /**
     * OpenCode Go catalog models. They use the shared benchmark OpenCode configuration, so there is no generated one.
     *
     * @param  list<string>  $selectedModels
     * @return array{absolutePath: null, reference: string, configContents: string, openCodeConfigContents: null, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}, models: list<array{provider: string, modelId: string, model: string, folder: string, port: int, routing: null}>}
     */
    public function openCodeGo(array $selectedModels, int $modelLimit = self::BENCHMARK_MODEL_LIMIT): array
    {
        $selectedModels = $this->normalizeModels($selectedModels);

        if ($selectedModels === [] || count($selectedModels) > $modelLimit) {
            throw new BenchmarkRunnerException('OpenCode Go kataloğundan 1–'.$modelLimit.' model seçin.');
        }

        $modelsById = OpenCodeGoModel::query()
            ->whereIn('model_id', $selectedModels)
            ->where('available', true)
            ->where('agent_compatible', true)
            ->get()
            ->keyBy('model_id');

        if ($modelsById->count() !== count($selectedModels)) {
            throw new BenchmarkRunnerException('Seçilen OpenCode Go modellerinden biri artık kullanılamıyor veya araç desteği sunmuyor. Kataloğu yenileyin.');
        }

        $ports = [];
        $models = [];
        $configLines = [
            '# BenchForge tarafından OpenCode Go kataloğundan oluşturuldu.',
            '# max_parallel=1',
        ];

        foreach ($selectedModels as $index => $modelId) {
            $port = self::CATALOG_PORT_START + $index;
            $ports[] = $port;
            $folder = self::folderFor('opencode-go/'.$modelId);
            $configLines[] = "{$folder}|opencode-go/{$modelId}|{$port}";
            $models[] = [
                'provider' => 'opencode-go',
                'modelId' => $modelId,
                'model' => 'opencode-go/'.$modelId,
                'folder' => $folder,
                'port' => $port,
                'routing' => null,
            ];
        }

        return [
            'absolutePath' => null,
            'reference' => 'custom:opencode-go',
            'configContents' => implode(PHP_EOL, $configLines).PHP_EOL,
            'openCodeConfigContents' => null,
            'option' => [
                'value' => 'custom:opencode-go',
                'label' => 'Özel OpenCode Go seçimi',
                'modelCount' => count($selectedModels),
                'ports' => $ports,
                'providers' => ['opencode-go'],
                'maxParallel' => 1,
            ],
            'models' => $models,
        ];
    }

    /**
     * Sets the requested concurrency in the selection's `models.conf` contents. Groups are copied, so the
     * selection always becomes an execution-local configuration.
     *
     * @param  array{absolutePath: ?string, reference: string, configContents: ?string, openCodeConfigContents: ?string, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}, models?: list<array<string, mixed>>}  $selection
     * @return array{absolutePath: null, reference: string, configContents: string, openCodeConfigContents: ?string, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}, models?: list<array<string, mixed>>}
     */
    public function withMaxParallel(array $selection, mixed $requestedMaxParallel): array
    {
        $maxParallel = filter_var($requestedMaxParallel, FILTER_VALIDATE_INT);
        $modelCount = $selection['option']['modelCount'];

        if ($maxParallel === false || $maxParallel < 1 || $maxParallel > $modelCount) {
            throw new BenchmarkRunnerException("Eşzamanlılık 1 ile {$modelCount} arasında olmalıdır.");
        }

        $contents = is_string($selection['configContents'])
            ? $selection['configContents']
            : File::get((string) $selection['absolutePath']);
        $parallelDirective = "# max_parallel={$maxParallel}";

        if (preg_match('/^#\s*max_parallel=\d+$/m', $contents) === 1) {
            $contents = (string) preg_replace('/^#\s*max_parallel=\d+$/m', $parallelDirective, $contents, 1);
        } else {
            $contents = $parallelDirective.PHP_EOL.$contents;
        }

        $selection['absolutePath'] = null;
        $selection['configContents'] = $contents;
        $selection['option']['maxParallel'] = $maxParallel;

        return $selection;
    }

    /**
     * The folder name of a provider-prefixed model (`openrouter/acme/model`, `opencode-go/model`), shared by catalog runs and saved groups.
     */
    public static function folderFor(string $providerPrefixedModel): string
    {
        $provider = Str::before($providerPrefixedModel, '/');
        $modelId = Str::after($providerPrefixedModel, '/');

        return match ($provider) {
            'openrouter' => 'or-'.Str::limit(Str::slug(Str::afterLast($modelId, '/')) ?: 'model', 36, '').'-'.substr(sha1($modelId), 0, 6),
            'opencode-go' => 'go-'.Str::limit(Str::slug($modelId) ?: 'model', 36, '').'-'.substr(sha1($modelId), 0, 6),
            default => Str::limit(Str::slug($provider.'-'.Str::afterLast($modelId, '/')) ?: 'model', 40, '').'-'.substr(sha1($providerPrefixedModel), 0, 6),
        };
    }

    /**
     * @param  list<mixed>  $models
     * @return list<string>
     */
    private function normalizeModels(array $models): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $model): string => trim((string) $model), $models),
        )));
    }

    /**
     * @return array{absolutePath: string, reference: string, configContents: null, openCodeConfigContents: null, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}, models: list<array{provider: string, modelId: string, model: string, folder: string, port: int, routing: null}>}
     */
    private function parseConfig(string $filename): array
    {
        $configRoot = realpath($this->projectRoot().'/config');
        $absolutePath = realpath($this->projectRoot().'/config/'.$filename);

        if ($configRoot === false || $absolutePath === false || ! $this->isWithin($configRoot, $absolutePath)) {
            throw new BenchmarkRunnerException('Model yapılandırması güvenli dizinin dışında.');
        }

        $ports = [];
        $providers = [];
        $models = [];
        $modelCount = 0;
        $maxParallel = null;

        foreach (preg_split('/\R/', File::get($absolutePath)) ?: [] as $index => $rawLine) {
            $line = trim($rawLine);

            if (preg_match('/^#\s*max_parallel=(\d+)$/', $line, $matches) === 1) {
                if ($maxParallel !== null) {
                    throw new BenchmarkRunnerException("{$filename}:".($index + 1).' max_parallel birden fazla tanımlanamaz.');
                }

                $maxParallel = (int) $matches[1];

                continue;
            }

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            if (count($parts) !== 3) {
                throw new BenchmarkRunnerException("{$filename}:".($index + 1).' geçerli bir model kaydı değil.');
            }

            [$folder, $model, $rawPort] = $parts;
            $port = filter_var($rawPort, FILTER_VALIDATE_INT);

            if (
                preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $folder) !== 1
                || $model === ''
                || preg_match('/\s/', $model) === 1
                || $port === false
                || $port < 1024
                || $port > 65535
            ) {
                throw new BenchmarkRunnerException("{$filename}:".($index + 1).' geçerli bir model kaydı değil.');
            }

            if (in_array($port, $ports, true)) {
                throw new BenchmarkRunnerException("{$filename} içinde {$port} portu birden fazla kullanılıyor.");
            }

            $ports[] = $port;
            $providers[] = Str::before($model, '/');
            $models[] = [
                'provider' => Str::before($model, '/'),
                'modelId' => Str::after($model, '/'),
                'model' => $model,
                'folder' => $folder,
                'port' => $port,
                'routing' => null,
            ];
            $modelCount++;
        }

        if ($modelCount === 0) {
            throw new BenchmarkRunnerException("{$filename} içinde çalıştırılabilir model bulunamadı.");
        }

        $maxParallel ??= $modelCount;

        if ($maxParallel < 1 || $maxParallel > $modelCount) {
            throw new BenchmarkRunnerException("{$filename} içinde max_parallel 1 ile {$modelCount} arasında olmalıdır.");
        }

        return [
            'absolutePath' => $absolutePath,
            'reference' => 'config/'.$filename,
            'configContents' => null,
            'openCodeConfigContents' => null,
            'option' => [
                'value' => 'config/'.$filename,
                'label' => $this->titleFromFilename($filename),
                'modelCount' => $modelCount,
                'ports' => $ports,
                'providers' => array_values(array_unique($providers)),
                'maxParallel' => $maxParallel,
            ],
            'models' => $models,
        ];
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $directory, string $extension): array
    {
        $realDirectory = realpath($directory);

        if ($realDirectory === false) {
            throw new BenchmarkRunnerException('Benchmark seçenek dizini bulunamadı.', ['directory' => $directory]);
        }

        return collect(File::files($realDirectory))
            ->filter(function (\SplFileInfo $file) use ($extension, $realDirectory): bool {
                $realPath = $file->getRealPath();

                return $file->isFile()
                    && str_ends_with($file->getFilename(), $extension)
                    && $realPath !== false
                    && $this->isWithin($realDirectory, $realPath);
            })
            ->map(fn (\SplFileInfo $file): string => $file->getFilename())
            ->sort(fn (string $left, string $right): int => strcoll($left, $right))
            ->values()
            ->all();
    }

    private function titleFromFilename(string $filename): string
    {
        return Str::of($filename)
            ->beforeLast('.')
            ->replace(['-', '_'], ' ')
            ->title()
            ->toString();
    }

    private function projectRoot(): string
    {
        $path = realpath((string) config('benchforge.root_path'));

        if ($path === false) {
            throw new BenchmarkRunnerException('BenchForge dosya yolu bulunamadı.', ['config' => 'root_path']);
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    private function isWithin(string $parent, string $candidate): bool
    {
        $normalizedParent = rtrim($parent, DIRECTORY_SEPARATOR);

        return $candidate === $normalizedParent
            || str_starts_with($candidate, $normalizedParent.DIRECTORY_SEPARATOR);
    }
}
