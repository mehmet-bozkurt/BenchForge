<?php

namespace Database\Factories;

use App\Models\Artifact;
use App\Models\BenchmarkRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Artifact>
 */
class ArtifactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'run:'.fake()->unique()->slug(2);

        return [
            'benchmark_run_id' => BenchmarkRun::factory(),
            'model_run_id' => null,
            'artifact_key' => $key,
            'kind' => 'file',
            'type' => 'summary',
            'label' => 'Test artifact',
            'path' => '/tmp/benchforge/'.$key,
            'relative_path' => 'runs/'.$key,
            'size_bytes' => fake()->numberBetween(1, 50000),
            'modified_at' => now(),
        ];
    }
}
