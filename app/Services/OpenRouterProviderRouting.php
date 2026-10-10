<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenRouterModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenRouterProviderRouting
{
    public const MODES = ['auto', 'price', 'throughput', 'fixed'];

    private const MODEL_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]*(?:\/[A-Za-z0-9][A-Za-z0-9._:-]*)+$/';

    /** @return array{modelId: string, endpoints: list<array<string, mixed>>, fetchedAt: string} */
    public function endpoints(string $modelId, bool $refresh = false): array
    {
        if (preg_match(self::MODEL_ID_PATTERN, $modelId) !== 1
            || ! OpenRouterModel::query()->where('model_id', $modelId)
                ->where('available', true)->where('agent_compatible', true)->exists()) {
            throw new BenchmarkRunnerException('Sağlayıcıları görmek için katalogdan kullanılabilir bir OpenRouter modeli seçin.');
        }

        $url = rtrim((string) config('services.openrouter.catalog_url'), '/').'/'.implode('/', array_map('rawurlencode', explode('/', $modelId))).'/endpoints';
        $cacheKey = 'benchforge:openrouter:endpoints:'.hash('sha256', $url);

        try {
            if ($refresh) {
                $result = $this->fetchEndpoints($url, $modelId);
                Cache::put($cacheKey, $result, 300);

                return $result;
            }

            return Cache::remember($cacheKey, 300, fn (): array => $this->fetchEndpoints($url, $modelId));
        } catch (Throwable) {
            throw new BenchmarkRunnerException('OpenRouter sağlayıcı listesi alınamadı. Bağlantıyı kontrol edip tekrar deneyin; otomatik sağlayıcıya geçilmedi.');
        }
    }

    /**
     * @param  list<string>  $models
     * @param  array<string, mixed>  $fixedProviders
     * @return array<string, array<string, mixed>>
     */
    public function routingForModels(array $models, string $mode = 'auto', array $fixedProviders = [], bool $refresh = false): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new BenchmarkRunnerException('Geçerli bir OpenRouter sağlayıcı tercihi seçin.');
        }

        if ($mode === 'fixed' && (count($fixedProviders) !== count($models) || array_diff(array_keys($fixedProviders), $models) !== [])) {
            throw new BenchmarkRunnerException('Seçilen her model için bir sabit sağlayıcı seçin.');
        }

        if ($mode === 'fixed') {
            foreach ($fixedProviders as $slug) {
                if (! is_string($slug) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,159}$/', $slug) !== 1) {
                    throw new BenchmarkRunnerException('Seçilen her model için bir sabit sağlayıcı seçin.');
                }
            }
        }

        $routing = [];

        foreach ($models as $modelId) {
            $provider = ['allow_fallbacks' => true, 'data_collection' => 'deny', 'require_parameters' => true];

            if (in_array($mode, ['price', 'throughput'], true)) {
                $provider['sort'] = $mode;
            } elseif ($mode === 'fixed') {
                $slug = $fixedProviders[$modelId];

                $endpoints = $this->endpoints($modelId, $refresh)['endpoints'];

                if (! in_array($slug, array_column($endpoints, 'slug'), true)) {
                    throw new BenchmarkRunnerException("{$modelId} için seçilen sağlayıcı artık listede yok veya araç desteği sunmuyor. Sağlayıcı listesini yenileyin.");
                }

                $provider['order'] = [$slug];
                $provider['only'] = [$slug];
                $provider['allow_fallbacks'] = false;
            }

            $routing[$modelId] = $provider;
        }

        return $routing;
    }

    /** @return array{modelId: string, endpoints: list<array<string, mixed>>, fetchedAt: string} */
    private function fetchEndpoints(string $url, string $modelId): array
    {
        $data = Http::connectTimeout(3)->timeout(6)->withoutRedirecting()->acceptJson()->get($url)->throw()->json('data');

        if (! is_array($data) || ($data['id'] ?? null) !== $modelId || ! is_array($data['endpoints'] ?? null)) {
            throw new BenchmarkRunnerException('OpenRouter geçerli bir sağlayıcı listesi döndürmedi.');
        }

        $endpoints = collect($data['endpoints'])
            ->filter(fn (mixed $endpoint): bool => is_array($endpoint)
                && is_string($endpoint['tag'] ?? null)
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,159}$/', $endpoint['tag']) === 1
                && is_array($endpoint['supported_parameters'] ?? null)
                && in_array('tools', $endpoint['supported_parameters'], true))
            ->map(fn (array $endpoint): array => [
                'slug' => $endpoint['tag'],
                'name' => is_string($endpoint['provider_name'] ?? null) ? $endpoint['provider_name'] : $endpoint['tag'],
                'promptPrice' => $this->price($endpoint['pricing']['prompt'] ?? null),
                'completionPrice' => $this->price($endpoint['pricing']['completion'] ?? null),
                'contextLength' => is_numeric($endpoint['context_length'] ?? null) ? (int) $endpoint['context_length'] : null,
                'quantization' => is_string($endpoint['quantization'] ?? null) ? $endpoint['quantization'] : null,
            ])
            ->unique('slug')->sortBy('slug')->values()->all();

        return ['modelId' => $modelId, 'endpoints' => $endpoints, 'fetchedAt' => now()->toIso8601String()];
    }

    private function price(mixed $price): ?string
    {
        return is_numeric($price) && is_finite((float) $price) && (float) $price >= 0
            ? number_format((float) $price, 12, '.', '')
            : null;
    }
}
