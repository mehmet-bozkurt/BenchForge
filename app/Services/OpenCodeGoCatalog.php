<?php

namespace App\Services;

use App\Models\OpenCodeGoModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenCodeGoCatalog
{
    private const MODEL_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    public function sync(): int
    {
        $responses = Http::pool(fn (Pool $pool): array => [
            $pool->as('availability')
                ->connectTimeout(4)
                ->timeout(20)
                ->acceptJson()
                ->get((string) config('services.opencode_go.models_url')),
            $pool->as('metadata')
                ->connectTimeout(4)
                ->timeout(20)
                ->acceptJson()
                ->get((string) config('services.opencode_go.metadata_url')),
        ]);

        $availableModels = collect($responses['availability']->throw()->json('data'))
            ->filter(fn (mixed $model): bool => is_array($model)
                && is_string($model['id'] ?? null)
                && preg_match(self::MODEL_ID_PATTERN, trim($model['id'])) === 1)
            ->mapWithKeys(fn (array $model): array => [trim($model['id']) => $model]);
        $metadata = collect($responses['metadata']->throw()->json('opencode-go.models'))
            ->filter(fn (mixed $model, mixed $modelId): bool => is_string($modelId)
                && is_array($model)
                && preg_match(self::MODEL_ID_PATTERN, $modelId) === 1);

        if ($availableModels->isEmpty() || $metadata->isEmpty()) {
            throw new RuntimeException('OpenCode Go kataloğu geçerli model verisi döndürmedi.');
        }

        $models = $metadata
            ->filter(fn (array $model, string $modelId): bool => $availableModels->has($modelId))
            ->map(fn (array $model, string $modelId): array => $this->normalize($modelId, $model, $availableModels->get($modelId)))
            ->values();

        if ($models->isEmpty()) {
            throw new RuntimeException('OpenCode Go model kaynakları eşleştirilemedi.');
        }

        $syncedAt = now();

        DB::transaction(function () use ($models, $syncedAt): void {
            OpenCodeGoModel::query()->update(['available' => false]);

            $models->chunk(100)->each(function (Collection $chunk) use ($syncedAt): void {
                OpenCodeGoModel::query()->upsert(
                    $chunk->map(fn (array $model): array => [
                        ...$model,
                        'synced_at' => $syncedAt,
                        'created_at' => $syncedAt,
                        'updated_at' => $syncedAt,
                    ])->all(),
                    ['model_id'],
                    [
                        'name',
                        'family',
                        'description',
                        'context_length',
                        'output_limit',
                        'input_cost',
                        'output_cost',
                        'cache_read_cost',
                        'cache_write_cost',
                        'reasoning_options',
                        'modalities',
                        'catalog_payload',
                        'agent_compatible',
                        'supports_reasoning',
                        'supports_attachments',
                        'is_free',
                        'is_preview',
                        'available',
                        'released_at',
                        'synced_at',
                        'updated_at',
                    ],
                );
            });
        });

        return $models->count();
    }

    /** @return Collection<int, OpenCodeGoModel> */
    public function selectableModels(): Collection
    {
        return OpenCodeGoModel::query()
            ->where('available', true)
            ->where('agent_compatible', true)
            ->orderByDesc('released_at')
            ->orderBy('name')
            ->get();
    }

    public function lastSyncedAt(): ?CarbonImmutable
    {
        return OpenCodeGoModel::query()->latest('synced_at')->first()?->synced_at;
    }

    /** @return array<string, mixed> */
    private function normalize(string $modelId, array $model, array $availability): array
    {
        $cost = is_array($model['cost'] ?? null) ? $model['cost'] : [];
        $limit = is_array($model['limit'] ?? null) ? $model['limit'] : [];
        $modalities = is_array($model['modalities'] ?? null) ? $model['modalities'] : [];
        $inputCost = $this->price($cost['input'] ?? null);
        $outputCost = $this->price($cost['output'] ?? null);

        return [
            'model_id' => $modelId,
            'name' => trim((string) ($model['name'] ?? $modelId)),
            'family' => filled($model['family'] ?? null) ? (string) $model['family'] : null,
            'description' => filled($model['description'] ?? null) ? (string) $model['description'] : null,
            'context_length' => is_numeric($limit['context'] ?? null) ? (int) $limit['context'] : null,
            'output_limit' => is_numeric($limit['output'] ?? null) ? (int) $limit['output'] : null,
            'input_cost' => $inputCost,
            'output_cost' => $outputCost,
            'cache_read_cost' => $this->price($cost['cache_read'] ?? null),
            'cache_write_cost' => $this->price($cost['cache_write'] ?? null),
            'reasoning_options' => json_encode(is_array($model['reasoning_options'] ?? null) ? $model['reasoning_options'] : [], JSON_THROW_ON_ERROR),
            'modalities' => json_encode($modalities, JSON_THROW_ON_ERROR),
            'catalog_payload' => json_encode([
                'metadata' => $model,
                'availability' => $availability,
            ], JSON_THROW_ON_ERROR),
            'agent_compatible' => (bool) ($model['tool_call'] ?? false),
            'supports_reasoning' => (bool) ($model['reasoning'] ?? false),
            'supports_attachments' => (bool) ($model['attachment'] ?? false),
            'is_free' => $inputCost === '0.000000' && $outputCost === '0.000000',
            'is_preview' => str_contains($modelId, 'preview'),
            'available' => true,
            'released_at' => is_string($model['release_date'] ?? null)
                ? CarbonImmutable::parse($model['release_date'])->startOfDay()
                : null,
        ];
    }

    private function price(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 6, '.', '');
    }
}
