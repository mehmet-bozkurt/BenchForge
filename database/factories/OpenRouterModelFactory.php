<?php

namespace Database\Factories;

use App\Models\OpenRouterModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpenRouterModel>
 */
class OpenRouterModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $author = fake()->randomElement(['anthropic', 'deepseek', 'google', 'openai']);
        $slug = fake()->unique()->slug(2);

        return [
            'model_id' => $author.'/'.$slug,
            'author' => $author,
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'description' => fake()->sentence(),
            'context_length' => fake()->randomElement([32768, 131072, 200000]),
            'prompt_price' => '0.000001000000',
            'completion_price' => '0.000004000000',
            'supported_parameters' => ['tools', 'tool_choice'],
            'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
            'agent_compatible' => true,
            'is_free' => false,
            'is_preview' => false,
            'available' => true,
            'released_at' => now()->subDays(fake()->numberBetween(1, 365)),
            'synced_at' => now(),
        ];
    }
}
