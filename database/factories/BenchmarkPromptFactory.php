<?php

namespace Database\Factories;

use App\Models\BenchmarkPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenchmarkPrompt>
 */
class BenchmarkPromptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);
        $content = fake()->paragraphs(3, true);

        return [
            'title' => $title,
            'slug' => str($title)->slug().'-'.fake()->unique()->numerify('###'),
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'active' => true,
        ];
    }
}
