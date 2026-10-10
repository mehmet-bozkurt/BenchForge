<?php

namespace Database\Factories;

use App\Models\OpenCodeGoModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpenCodeGoModel>
 */
class OpenCodeGoModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $family = fake()->randomElement(['deepseek', 'glm', 'kimi', 'qwen']);
        $slug = $family.'-'.fake()->unique()->slug(2);

        return [
            'model_id' => $slug,
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'family' => $family,
            'description' => fake()->sentence(),
            'context_length' => fake()->randomElement([262144, 1000000]),
            'output_limit' => fake()->randomElement([65536, 131072]),
            'input_cost' => '0.150000',
            'output_cost' => '0.600000',
            'cache_read_cost' => '0.030000',
            'cache_write_cost' => null,
            'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'high']]],
            'modalities' => ['input' => ['text'], 'output' => ['text']],
            'agent_compatible' => true,
            'supports_reasoning' => true,
            'supports_attachments' => false,
            'is_free' => false,
            'is_preview' => false,
            'available' => true,
            'released_at' => now()->subDays(fake()->numberBetween(1, 365)),
            'synced_at' => now(),
        ];
    }
}
