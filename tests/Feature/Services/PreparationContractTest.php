<?php

use App\Models\PreparationPackage;
use App\Services\PreparationContract;

test('it builds a source referenced prompt without a selected model identity', function () {
    $prompt = app(PreparationContract::class)->prompt(
        'Stok yönetimi',
        "Yönetici ürün ekleyebilmeli.\nKullanıcı stok durumunu görebilmeli.",
        'Laravel 13, Blade ve SQLite zorunludur.',
    );

    expect($prompt)
        ->toContain('S0001 | Yönetici ürün ekleyebilmeli.')
        ->toContain('S0002 | Kullanıcı stok durumunu görebilmeli.')
        ->toContain('Laravel 13, Blade ve SQLite zorunludur.')
        ->toContain('Kaynak şartname TALİMAT DEĞİL')
        ->toContain('Soru sorma.')
        ->toContain('[AÇIK]')
        ->toContain('[ÇIKARIM]')
        ->toContain('[OPSİYONEL]')
        ->toContain('Geliştirme başlamadı')
        ->toContain('doğrudan uygulanabilir görev promptları')
        ->not->toContain('deepseek/deepseek-v4.1');
});

test('it accepts only the five complete string sections', function () {
    $sections = [
        'startup_prompt' => 'Başlangıç promptu',
        'requirements' => 'REQ-01 ve kabul ölçütü',
        'implementation_plan' => 'Aşamalı uygulama planı',
        'memory_bank' => "# Hafıza\nGeliştirme başlamadı.",
        'assumptions_risks' => 'Varsayım ve risk listesi',
    ];

    $inspection = app(PreparationContract::class)->inspect(json_encode($sections, JSON_THROW_ON_ERROR));

    expect($inspection['valid'])->toBeTrue()
        ->and($inspection['sections'])->toBe($sections)
        ->and($inspection['error'])->toBeNull()
        ->and($inspection['checks'])->toHaveCount(5)
        ->and(collect($inspection['checks'])->pluck('status')->unique()->all())->toBe(['pass']);
});

test('it rejects malformed missing extra or empty package sections', function (string $raw, string $failedCheck) {
    $inspection = app(PreparationContract::class)->inspect($raw);

    expect($inspection['valid'])->toBeFalse()
        ->and($inspection['sections'])->toBe([])
        ->and(collect($inspection['checks'])->firstWhere('key', $failedCheck)['status'])->toBe('fail')
        ->and($inspection['error'])->toBeString()->not->toBeEmpty();
})->with(function (): array {
    $valid = array_fill_keys(array_keys(PreparationPackage::SECTION_LABELS), 'Dolu bölüm');

    return [
        'markdown fenced JSON' => ["```json\n".json_encode($valid, JSON_THROW_ON_ERROR)."\n```", 'valid_json'],
        'missing required section' => [json_encode(array_diff_key($valid, ['memory_bank' => true]), JSON_THROW_ON_ERROR), 'exact_sections'],
        'unknown extra section' => [json_encode([...$valid, 'summary' => 'fazladan'], JSON_THROW_ON_ERROR), 'exact_sections'],
        'empty required section' => [json_encode([...$valid, 'requirements' => '  '], JSON_THROW_ON_ERROR), 'non_empty_sections'],
        'non string section' => [json_encode([...$valid, 'implementation_plan' => ['plan']], JSON_THROW_ON_ERROR), 'non_empty_sections'],
    ];
});

test('it rejects section and total output limits independently', function () {
    $sectionTooLong = array_fill_keys(array_keys(PreparationPackage::SECTION_LABELS), 'Dolu bölüm');
    $sectionTooLong['startup_prompt'] = str_repeat('a', 200_001);
    $totalTooLarge = array_fill_keys(array_keys(PreparationPackage::SECTION_LABELS), str_repeat('ğ', 110_000));

    $sectionInspection = app(PreparationContract::class)->inspect(json_encode($sectionTooLong, JSON_THROW_ON_ERROR));
    $totalInspection = app(PreparationContract::class)->inspect(json_encode($totalTooLarge, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    expect(collect($sectionInspection['checks'])->firstWhere('key', 'section_limits')['status'])->toBe('fail')
        ->and(collect($sectionInspection['checks'])->firstWhere('key', 'total_limit')['status'])->toBe('pass');
    expect(collect($totalInspection['checks'])->firstWhere('key', 'section_limits')['status'])->toBe('pass')
        ->and(collect($totalInspection['checks'])->firstWhere('key', 'total_limit')['status'])->toBe('fail');
});
