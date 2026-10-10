<?php

namespace Database\Factories;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelRun>
 */
class ModelRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benchmark_run_id' => BenchmarkRun::factory(),
            'slug' => fake()->unique()->slug(2),
            'model' => 'opencode-go/'.fake()->unique()->slug(2),
            'status' => 'pass',
            'duration_seconds' => fake()->numberBetween(30, 3600),
            'failed_stages' => [],
        ];
    }
}
