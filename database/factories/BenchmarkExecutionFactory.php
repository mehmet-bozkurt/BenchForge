<?php

namespace Database\Factories;

use App\Models\BenchmarkExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenchmarkExecution>
 */
class BenchmarkExecutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $runId = fake()->unique()->slug(3);

        return [
            'run_id' => $runId,
            'status' => 'running',
            'prompt_path' => 'prompts/nova-marketplace-v1.txt',
            'config_path' => 'config/nova-modeller.conf',
            'routes' => ['/', '/search'],
            'model_count' => 5,
            'completed_model_count' => 2,
            'progress_percent' => 40,
            'phase' => 'Modeller üretiliyor ve doğrulanıyor',
            'message' => 'Benchmark çalışıyor.',
            'runner_pid' => 12345,
            'benchmark_pid' => 12346,
            'state_path' => storage_path('framework/testing/'.$runId.'/state.json'),
            'log_path' => storage_path('framework/testing/'.$runId.'/runner.log'),
            'started_at' => now(),
        ];
    }
}
