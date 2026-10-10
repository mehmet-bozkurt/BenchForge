<?php

namespace Database\Factories;

use App\Models\PreparationComparison;
use App\Models\PreparationPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PreparationPackage>
 */
class PreparationPackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'preparation_comparison_id' => PreparationComparison::factory(),
            'model_id' => 'fixture/'.fake()->unique()->slug(2),
            'model_name' => 'Örnek hazırlık modeli',
            'status' => 'completed',
            'startup_prompt' => 'Şartnameye ve teknik sınırlara bağlı kalarak aşamalı geliştirme yap.',
            'requirements' => 'REQ-01: Yönetici ürün ekleyebilir. Kaynak: S1. Kabul: Eklenen ürün listede görünür.',
            'implementation_plan' => 'Aşama 1: Veri modelini oluştur. Doğrulama: Hedefli testler geçmeli.',
            'memory_bank' => '# Proje hafızası\nDurum: Geliştirme başlamadı. Sıradaki iş: Veri modeli.',
            'assumptions_risks' => 'Varsayım: Tek yönetici rolü. Risk: Entegrasyon bilgisi verilmedi.',
            'structural_checks' => [['key' => 'sections', 'label' => 'Beş bölüm', 'status' => 'pass', 'message' => 'Beş bölüm de mevcut.']],
            'duration_seconds' => 12,
        ];
    }
}
