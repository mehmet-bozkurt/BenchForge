<?php

namespace App\Services;

use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;

class CatalogModelDetails
{
    private const LABELS = [
        'id' => 'Model kimliği',
        'canonical_slug' => 'Kanonik kimlik',
        'hugging_face_id' => 'Hugging Face kimliği',
        'name' => 'Model adı',
        'author' => 'Yayıncı',
        'family' => 'Model ailesi',
        'description' => 'Açıklama',
        'created' => 'Katalog yayın tarihi',
        'release_date' => 'Çıkış tarihi',
        'last_updated' => 'Modelin son güncellemesi',
        'knowledge' => 'Bilgi kesim tarihi',
        'knowledge_cutoff' => 'Bilgi kesim tarihi',
        'expiration_date' => 'Son kullanım tarihi',
        'context_length' => 'Bağlam penceresi (token)',
        'architecture.modality' => 'Modalite',
        'architecture.input_modalities' => 'Girdi türleri',
        'architecture.output_modalities' => 'Çıktı türleri',
        'architecture.tokenizer' => 'Tokenizer',
        'architecture.instruct_type' => 'Yönerge biçimi',
        'top_provider.context_length' => 'Üst sağlayıcı bağlam sınırı (token)',
        'top_provider.max_completion_tokens' => 'Üst sağlayıcı çıktı sınırı (token)',
        'top_provider.is_moderated' => 'Üst sağlayıcı moderasyonu',
        'supported_parameters' => 'Desteklenen parametreler',
        'default_parameters' => 'Varsayılan parametreler',
        'default_params' => 'Varsayılan parametreler',
        'per_request_limits' => 'İstek başına sınırlar',
        'tool_call' => 'Araç çağırma desteği',
        'reasoning' => 'Akıl yürütme desteği',
        'reasoning_options' => 'Akıl yürütme seçenekleri',
        'attachment' => 'Dosya eki desteği',
        'temperature' => 'Temperature desteği',
        'structured_output' => 'Yapılandırılmış çıktı desteği',
        'open_weights' => 'Açık ağırlıklar',
        'interleaved' => 'İç içe akıl yürütme biçimi',
        'status' => 'Model durumu',
        'experimental' => 'Deneysel model',
        'limit.context' => 'Bağlam penceresi (token)',
        'limit.input' => 'Girdi sınırı (token)',
        'limit.output' => 'Çıktı sınırı (token)',
        'modalities.input' => 'Girdi türleri',
        'modalities.output' => 'Çıktı türleri',
        'agent_compatible' => 'Kodlama aracı uyumu (BenchForge)',
        'is_preview' => 'Önizleme sürümü (BenchForge)',
    ];

    private const PRICING_LABELS = [
        'prompt' => 'Metin girdisi',
        'completion' => 'Metin çıktısı',
        'input' => 'Girdi referans fiyatı',
        'output' => 'Çıktı referans fiyatı',
        'input_cache_read' => 'Önbellekten girdi okuma',
        'input_cache_write' => 'Girdi önbelleğine yazma',
        'input_cache_write_1h' => 'Girdi önbelleğine yazma (1 saat)',
        'cache_read' => 'Önbellek okuma referans fiyatı',
        'cache_write' => 'Önbellek yazma referans fiyatı',
        'internal_reasoning' => 'İç akıl yürütme',
        'audio' => 'Ses girdisi',
        'audio_output' => 'Ses çıktısı',
        'input_audio_cache' => 'Ses girdisi önbelleği',
        'image_token' => 'Görüntü tokenı',
        'image' => 'Görüntü girdisi',
        'image_output' => 'Görüntü çıktısı',
        'request' => 'İstek',
        'web_search' => 'Web araması',
        'discount' => 'İndirim oranı',
        'overrides' => 'Koşullu fiyat kuralları',
    ];

    private const TOKEN_PRICES = [
        'prompt', 'completion', 'internal_reasoning', 'audio', 'audio_output',
        'image_token', 'input_audio_cache', 'input_cache_read', 'input_cache_write',
        'input_cache_write_1h',
    ];

    /**
     * @return array{
     *     inputPrice: string, outputPrice: string, sourceName: string, sourceUrl: string,
     *     priceNotice: string, syncedAt: string, hasRawMetadata: bool, rawJson: string,
     *     fields: list<array{label: string, value: string}>,
     *     pricing: list<array{label: string, value: string, unit: string}>
     * }
     */
    public function forModel(OpenRouterModel|OpenCodeGoModel $model): array
    {
        $isOpenRouter = $model instanceof OpenRouterModel;
        $payload = $model->catalog_payload;
        $hasRawMetadata = is_array($payload) && $payload !== [];
        $payload = $hasRawMetadata ? $payload : $this->fallbackPayload($model);
        $metadata = $isOpenRouter ? $payload : ($payload['metadata'] ?? []);
        $metadata = is_array($metadata) ? $metadata : [];
        $prices = $metadata[$isOpenRouter ? 'pricing' : 'cost'] ?? [];
        $prices = is_array($prices) ? $prices : [];
        $fields = $this->fields($metadata);

        if (! $isOpenRouter && is_array($payload['availability'] ?? null)) {
            foreach ($this->fields($payload['availability']) as $field) {
                $fields[] = ['label' => 'Go erişim kataloğu / '.$field['label'], 'value' => $field['value']];
            }
        }

        return [
            'inputPrice' => $isOpenRouter ? $this->money($prices['prompt'] ?? null, 6) : 'Katalogdan doğrulanamıyor',
            'outputPrice' => $isOpenRouter ? $this->money($prices['completion'] ?? null, 6) : 'Katalogdan doğrulanamıyor',
            'sourceName' => $isOpenRouter ? 'OpenRouter resmî model kataloğu' : 'models.dev referans metadatası',
            'sourceUrl' => $isOpenRouter ? 'https://openrouter.ai/api/v1/models' : 'https://models.dev/api.json',
            'priceNotice' => $isOpenRouter
                ? 'Yayımlanan katalog birim fiyatlarıdır; toplam maliyet tahmini veya kesin fatura değildir. Sağlayıcı, koşullu fiyat kuralları ve ek işlem ücretleri nihai tutarı değiştirebilir.'
                : 'models.dev maliyetleri referans metadatasıdır; OpenCode Go resmî tarifesi veya ek fatura değildir. Go abonelik ve kullanım kotasıyla çalışır; model ve bağlam boyutu kota tüketimini değiştirebilir. Resmî koşullar: https://opencode.ai/docs/go/',
            'syncedAt' => $model->synced_at?->format('d.m.Y H:i T') ?? 'Bilinmiyor',
            'hasRawMetadata' => $hasRawMetadata,
            'rawJson' => $this->json($payload),
            'fields' => $fields,
            'pricing' => $this->pricing($prices, $isOpenRouter),
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackPayload(OpenRouterModel|OpenCodeGoModel $model): array
    {
        if ($model instanceof OpenRouterModel) {
            return [
                'id' => $model->model_id,
                'name' => $model->name,
                'author' => $model->author,
                'description' => $model->description,
                'context_length' => $model->context_length,
                'pricing' => ['prompt' => $model->prompt_price, 'completion' => $model->completion_price],
                'architecture' => $model->architecture,
                'supported_parameters' => $model->supported_parameters,
                'release_date' => $model->released_at?->format('Y-m-d'),
                'agent_compatible' => $model->agent_compatible,
                'is_preview' => $model->is_preview,
            ];
        }

        return [
            'metadata' => [
                'id' => $model->model_id,
                'name' => $model->name,
                'family' => $model->family,
                'description' => $model->description,
                'limit' => ['context' => $model->context_length, 'output' => $model->output_limit],
                'cost' => [
                    'input' => $model->input_cost,
                    'output' => $model->output_cost,
                    'cache_read' => $model->cache_read_cost,
                    'cache_write' => $model->cache_write_cost,
                ],
                'modalities' => $model->modalities,
                'reasoning_options' => $model->reasoning_options,
                'tool_call' => $model->agent_compatible,
                'reasoning' => $model->supports_reasoning,
                'attachment' => $model->supports_attachments,
                'release_date' => $model->released_at?->format('Y-m-d'),
                'is_preview' => $model->is_preview,
            ],
        ];
    }

    /** @return list<array{label: string, value: string}> */
    private function fields(array $metadata): array
    {
        $fields = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, ['pricing', 'cost'], true)) {
                continue;
            }

            if (in_array($key, ['architecture', 'top_provider', 'limit', 'modalities'], true) && is_array($value) && $value !== []) {
                foreach ($value as $nestedKey => $nestedValue) {
                    $path = $key.'.'.$nestedKey;
                    $fields[] = ['label' => self::LABELS[$path] ?? $path, 'value' => $this->value($nestedValue)];
                }

                continue;
            }

            $displayValue = $key === 'created' && is_numeric($value)
                ? gmdate('Y-m-d H:i', (int) $value).' UTC'
                : $this->value($value);
            $fields[] = ['label' => self::LABELS[$key] ?? (string) $key, 'value' => $displayValue];
        }

        return $fields;
    }

    /** @return list<array{label: string, value: string, unit: string}> */
    private function pricing(array $prices, bool $isOpenRouter): array
    {
        $rows = [];

        foreach ($prices as $key => $value) {
            $knownTokenPrice = $isOpenRouter
                ? in_array($key, self::TOKEN_PRICES, true)
                : in_array($key, ['input', 'output', 'cache_read', 'cache_write'], true);
            $unit = match (true) {
                is_array($value) => 'Kaynak JSON — koşullar ve birimler korunur',
                $knownTokenPrice => $isOpenRouter ? 'USD / 1 milyon token' : 'Referans USD / 1 milyon token (models.dev)',
                $isOpenRouter && in_array($key, ['image', 'image_output'], true) => 'USD / görüntü',
                $isOpenRouter && $key === 'request' => 'USD / istek',
                $isOpenRouter && $key === 'web_search' => 'USD / arama',
                $isOpenRouter && $key === 'discount' => '%',
                default => 'Kaynak birimi belirtilmemiş',
            };
            $displayValue = match (true) {
                is_array($value) => $this->json($value),
                $knownTokenPrice => $this->money($value, $isOpenRouter ? 6 : 0),
                $isOpenRouter && in_array($key, ['image', 'image_output', 'request', 'web_search'], true) => $this->money($value),
                $isOpenRouter && $key === 'discount' => $this->decimal($value, 2) ?? 'Yayımlanmamış',
                default => $this->value($value),
            };
            $rows[] = ['label' => self::PRICING_LABELS[$key] ?? (string) $key, 'value' => $displayValue, 'unit' => $unit];
        }

        return $rows;
    }

    private function money(mixed $value, int $shift = 0): string
    {
        $decimal = $this->decimal($value, $shift);

        if ($decimal !== null && str_starts_with($decimal, '-')) {
            return 'Sabit fiyat yayımlanmamış (kaynak: '.trim((string) $value).')';
        }

        return $decimal === null ? 'Yayımlanmamış' : '$'.$decimal;
    }

    /** Decimal shifts preserve tiny published rates without float rounding. */
    private function decimal(mixed $value, int $shift = 0): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $raw = trim((string) $value);

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D', $raw, $parts) !== 1
            || ($parts[2] === '' && ($parts[3] ?? '') === '')) {
            return null;
        }

        $whole = $parts[2];
        $fraction = $parts[3] ?? '';
        $digits = $whole.$fraction;
        $position = strlen($whole) + (int) ($parts[4] ?? 0) + $shift;

        if (abs($position) > 1000) {
            return $raw.($shift === 0 ? '' : ' × 10^'.$shift);
        }

        if ($position <= 0) {
            $digits = str_repeat('0', -$position).$digits;
            $position = 0;
        } elseif ($position > strlen($digits)) {
            $digits .= str_repeat('0', $position - strlen($digits));
        }

        $integer = ltrim(substr($digits, 0, $position), '0');
        $fraction = rtrim(substr($digits, $position), '0');
        $decimal = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return ($parts[1] === '-' && $decimal !== '0' ? '-' : '').$decimal;
    }

    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'Yayımlanmamış',
            is_bool($value) => $value ? 'Evet' : 'Hayır',
            is_array($value) => $this->json($value),
            default => (string) $value,
        };
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
