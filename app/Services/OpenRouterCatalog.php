<?php

namespace App\Services;

use App\Models\OpenRouterModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OpenRouterCatalog
{
    private const MODEL_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]*(?:\/[A-Za-z0-9][A-Za-z0-9._:-]*)+$/';

    public function sync(): int
    {
        $models = collect(
            Http::connectTimeout(4)
                ->timeout(20)
                ->retry([250, 750])
                ->acceptJson()
                ->get((string) config('services.openrouter.catalog_url'))
                ->throw()
                ->json('data'),
        )
            ->filter(fn (mixed $model): bool => is_array($model)
                && is_string($model['id'] ?? null)
                && preg_match(self::MODEL_ID_PATTERN, trim($model['id'])) === 1)
            ->map(fn (array $model): array => $this->normalize($model))
            ->values();

        if ($models->isEmpty()) {
            throw new \RuntimeException('OpenRouter kataloğu geçerli model döndürmedi.');
        }

        $syncedAt = now();

        DB::transaction(function () use ($models, $syncedAt): void {
            OpenRouterModel::query()->update(['available' => false]);

            $models->chunk(150)->each(function (Collection $chunk) use ($syncedAt): void {
                OpenRouterModel::query()->upsert(
                    $chunk->map(fn (array $model): array => [
                        ...$model,
                        'synced_at' => $syncedAt,
                        'created_at' => $syncedAt,
                        'updated_at' => $syncedAt,
                    ])->all(),
                    ['model_id'],
                    [
                        'author',
                        'name',
                        'description',
                        'context_length',
                        'prompt_price',
                        'completion_price',
                        'supported_parameters',
                        'architecture',
                        'catalog_payload',
                        'agent_compatible',
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

    /** @return Collection<int, OpenRouterModel> */
    public function selectableModels(): Collection
    {
        return OpenRouterModel::query()
            ->where('available', true)
            ->where('agent_compatible', true)
            ->orderByDesc('released_at')
            ->orderBy('name')
            ->get();
    }

    public function lastSyncedAt(): ?CarbonImmutable
    {
        return OpenRouterModel::query()->latest('synced_at')->first()?->synced_at;
    }

    /** @return array<string, mixed> */
    private function normalize(array $model): array
    {
        $modelId = trim((string) $model['id']);
        $pricing = is_array($model['pricing'] ?? null) ? $model['pricing'] : [];
        $supportedParameters = array_values(array_filter(
            is_array($model['supported_parameters'] ?? null) ? $model['supported_parameters'] : [],
            'is_string',
        ));
        $promptPrice = $this->price($pricing['prompt'] ?? null);
        $completionPrice = $this->price($pricing['completion'] ?? null);

        return [
            'model_id' => $modelId,
            'author' => str($modelId)->before('/')->toString(),
            'name' => trim((string) ($model['name'] ?? $modelId)),
            'description' => filled($model['description'] ?? null) ? (string) $model['description'] : null,
            'context_length' => is_numeric($model['context_length'] ?? null) ? (int) $model['context_length'] : null,
            'prompt_price' => $promptPrice,
            'completion_price' => $completionPrice,
            'supported_parameters' => json_encode($supportedParameters, JSON_THROW_ON_ERROR),
            'architecture' => json_encode(is_array($model['architecture'] ?? null) ? $model['architecture'] : [], JSON_THROW_ON_ERROR),
            'catalog_payload' => json_encode($model, JSON_THROW_ON_ERROR),
            'agent_compatible' => in_array('tools', $supportedParameters, true),
            'is_free' => str_ends_with($modelId, ':free') || ($promptPrice === '0.000000000000' && $completionPrice === '0.000000000000'),
            'is_preview' => str_contains($modelId, 'preview'),
            'available' => true,
            'released_at' => is_numeric($model['created'] ?? null)
                ? CarbonImmutable::createFromTimestampUTC((int) $model['created'])
                : null,
        ];
    }

    private function price(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 12, '.', '');
    }
}
