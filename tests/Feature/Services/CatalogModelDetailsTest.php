<?php

use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Services\CatalogModelDetails;

test('it distinguishes unpublished zero tiny and scientific token rates', function (mixed $input, string $expected) {
    $model = OpenRouterModel::factory()->make();
    $model->catalog_payload = ['id' => 'example/model', 'pricing' => ['prompt' => $input, 'completion' => $input]];

    $details = app(CatalogModelDetails::class)->forModel($model);

    expect($details['inputPrice'])->toBe($expected);
    expect($details['outputPrice'])->toBe($expected);
})->with([
    'missing rate' => [null, 'Yayımlanmamış'],
    'published zero' => ['0', '$0'],
    'negative routing sentinel' => ['-1', 'Sabit fiyat yayımlanmamış (kaynak: -1)'],
    'tiny nonzero rate' => ['0.0000000000001', '$0.0000001'],
    'scientific notation' => ['1.25e-9', '$0.00125'],
    'very small scientific notation' => ['4e-20', '$0.00000000000004'],
    'ordinary catalog rate' => ['0.0000015', '$1.5'],
    'malformed catalog rate' => ['unknown', 'Yayımlanmamış'],
]);

test('it preserves every published price rule and uses only known source units', function () {
    $model = OpenRouterModel::factory()->make();
    $payload = [
        'id' => 'example/model',
        'pricing' => [
            'prompt' => '0',
            'audio' => '2e-6',
            'input_cache_write_1h' => '0.000003',
            'image' => '0.002',
            'image_output' => '0.004',
            'request' => '0.001',
            'web_search' => '0.01',
            'discount' => '0.25',
            'future_price' => '0.007',
            'unpublished_price' => null,
            'overrides' => [['condition' => ['min_context' => 200000], 'pricing' => ['prompt' => '0.000004']]],
        ],
        'future_capability' => ['enabled' => false, 'version' => 2],
    ];
    $model->catalog_payload = $payload;

    $details = app(CatalogModelDetails::class)->forModel($model);
    $prices = collect($details['pricing'])->keyBy('label');

    expect(json_decode($details['rawJson'], true, flags: JSON_THROW_ON_ERROR))->toBe($payload);
    expect($prices)->toHaveCount(11);
    expect($prices->get('Ses girdisi'))->toBe(['label' => 'Ses girdisi', 'value' => '$2', 'unit' => 'USD / 1 milyon token']);
    expect($prices->get('Girdi önbelleğine yazma (1 saat)')['value'])->toBe('$3');
    expect($prices->get('Görüntü girdisi')['unit'])->toBe('USD / görüntü');
    expect($prices->get('Görüntü çıktısı')['value'])->toBe('$0.004');
    expect($prices->get('İstek')['unit'])->toBe('USD / istek');
    expect($prices->get('Web araması')['unit'])->toBe('USD / arama');
    expect($prices->get('İndirim oranı')['value'])->toBe('25');
    expect($prices->get('future_price'))->toBe(['label' => 'future_price', 'value' => '0.007', 'unit' => 'Kaynak birimi belirtilmemiş']);
    expect($prices->get('unpublished_price')['value'])->toBe('Yayımlanmamış');
    expect(json_decode($prices->get('Koşullu fiyat kuralları')['value'], true, flags: JSON_THROW_ON_ERROR))->toBe($payload['pricing']['overrides']);
    expect(collect($details['fields'])->firstWhere('label', 'future_capability')['value'])->toContain('"enabled": false');
    expect($details['priceNotice'])->toContain('Sağlayıcı', 'koşullu fiyat', 'kesin fatura değildir');
});

test('it exposes published model limits capabilities dates and defaults without database internals', function () {
    $model = OpenRouterModel::factory()->make();
    $payload = [
        'id' => 'example/model',
        'description' => 'Türkçe açıklama',
        'context_length' => 200000,
        'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text'], 'tokenizer' => 'Example'],
        'top_provider' => ['max_completion_tokens' => 10000, 'is_moderated' => false],
        'supported_parameters' => ['tools', 'reasoning'],
        'default_parameters' => ['temperature' => 0.5],
        'per_request_limits' => null,
        'created' => 0,
        'expiration_date' => '2027-01-01',
    ];
    $model->catalog_payload = $payload;

    $details = app(CatalogModelDetails::class)->forModel($model);
    $fields = collect($details['fields'])->keyBy('label');

    expect($details['hasRawMetadata'])->toBeTrue();
    expect($details['sourceName'])->toBe('OpenRouter resmî model kataloğu');
    expect($details['sourceUrl'])->toBe('https://openrouter.ai/api/v1/models');
    expect($fields->get('Üst sağlayıcı çıktı sınırı (token)')['value'])->toBe('10000');
    expect($fields->get('Üst sağlayıcı moderasyonu')['value'])->toBe('Hayır');
    expect($fields->get('Tokenizer')['value'])->toBe('Example');
    expect($fields->get('İstek başına sınırlar')['value'])->toBe('Yayımlanmamış');
    expect($fields->get('Katalog yayın tarihi')['value'])->toBe('1970-01-01 00:00 UTC');
    expect($details['rawJson'])->not->toContain('created_at', 'updated_at', 'synced_at');
});

test('it keeps models dev reference rates separate from official go subscription pricing', function () {
    $model = OpenCodeGoModel::factory()->make();
    $payload = [
        'metadata' => [
            'id' => 'example-model',
            'family' => 'example',
            'cost' => ['input' => '0.15', 'output' => '0', 'cache_read' => null, 'context_over_200k' => ['input' => 0.3]],
            'tool_call' => true,
            'reasoning' => true,
            'attachment' => false,
            'knowledge' => '2026-01',
            'limit' => ['context' => 262144, 'output' => 65536],
        ],
        'availability' => ['id' => 'example-model', 'future_availability' => 'published'],
    ];
    $model->catalog_payload = $payload;

    $details = app(CatalogModelDetails::class)->forModel($model);
    $prices = collect($details['pricing'])->keyBy('label');
    $fields = collect($details['fields'])->keyBy('label');

    expect($details['inputPrice'])->toBe('Katalogdan doğrulanamıyor');
    expect($details['outputPrice'])->toBe('Katalogdan doğrulanamıyor');
    expect($details['sourceName'])->toBe('models.dev referans metadatası');
    expect($details['sourceUrl'])->toBe('https://models.dev/api.json');
    expect($details['priceNotice'])->toContain('resmî tarifesi', 'kota', 'bağlam boyutu', 'https://opencode.ai/docs/go/');
    expect($prices->get('Girdi referans fiyatı'))->toBe(['label' => 'Girdi referans fiyatı', 'value' => '$0.15', 'unit' => 'Referans USD / 1 milyon token (models.dev)']);
    expect($prices->get('Çıktı referans fiyatı')['value'])->toBe('$0');
    expect($prices->get('Önbellek okuma referans fiyatı')['value'])->toBe('Yayımlanmamış');
    expect($fields->get('Dosya eki desteği')['value'])->toBe('Hayır');
    expect($fields->get('Go erişim kataloğu / future_availability')['value'])->toBe('published');
    expect(json_decode($details['rawJson'], true, flags: JSON_THROW_ON_ERROR))->toBe($payload);
});

test('it falls back to public normalized openrouter metadata for older catalog entries', function () {
    $model = OpenRouterModel::factory()->make([
        'prompt_price' => '0.000000000001',
        'completion_price' => null,
        'description' => null,
        'synced_at' => null,
    ]);

    $details = app(CatalogModelDetails::class)->forModel($model);
    $raw = json_decode($details['rawJson'], true, flags: JSON_THROW_ON_ERROR);

    expect($details['hasRawMetadata'])->toBeFalse();
    expect($details['inputPrice'])->toBe('$0.000001');
    expect($details['outputPrice'])->toBe('Yayımlanmamış');
    expect($details['syncedAt'])->toBe('Bilinmiyor');
    expect($raw['id'])->toBe($model->model_id);
    expect(array_keys($raw))->toBe([
        'id', 'name', 'author', 'description', 'context_length', 'pricing',
        'architecture', 'supported_parameters', 'release_date', 'agent_compatible', 'is_preview',
    ]);
});

test('it falls back to normalized go reference prices without claiming official rates', function () {
    $model = OpenCodeGoModel::factory()->make(['input_cost' => null, 'output_cost' => '0']);

    $details = app(CatalogModelDetails::class)->forModel($model);
    $prices = collect($details['pricing'])->keyBy('label');

    expect($details['hasRawMetadata'])->toBeFalse();
    expect($details['inputPrice'])->toBe('Katalogdan doğrulanamıyor');
    expect($prices->get('Girdi referans fiyatı')['value'])->toBe('Yayımlanmamış');
    expect($prices->get('Çıktı referans fiyatı')['value'])->toBe('$0');
    expect($details['rawJson'])->not->toContain('created_at', 'updated_at', 'is_free');
});
