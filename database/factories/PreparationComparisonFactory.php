<?php

namespace Database\Factories;

use App\Models\PreparationComparison;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PreparationComparison>
 */
class PreparationComparisonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Örnek proje hazırlığı',
            'specification' => 'Yönetici ürünleri ekleyebilmeli ve düzenleyebilmelidir. Kullanıcılar ürün listesini görüntüleyebilmelidir.',
            'technologies' => 'Laravel, Blade ve SQLite. Yerel geliştirme; dağıtım yapılmayacak.',
            'provider' => 'openrouter',
            'selected_models' => ['fixture/preparation-model'],
            'source_hash' => hash('sha256', 'fixture-specification'),
            'contract_version' => 'v1',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'message' => 'Hazırlık paketleri üretildi.',
        ];
    }
}
