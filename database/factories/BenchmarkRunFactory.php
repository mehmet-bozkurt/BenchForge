<?php

namespace Database\Factories;

use App\Models\BenchmarkRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenchmarkRun>
 */
class BenchmarkRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(3);

        return [
            'slug' => $slug,
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'source_path' => '/tmp/benchforge/'.$slug,
            'source_fingerprint' => hash('sha256', $slug),
            'schema' => 'extended',
            'started_at' => now(),
            'metadata' => ['test' => $slug],
            'routes' => ['/'],
            'stage_names' => ['opencode', 'lint', 'build'],
            'model_count' => 0,
            'passed_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'unknown_count' => 0,
            'total_duration_seconds' => 0,
            'synced_at' => now(),
        ];
    }
}
