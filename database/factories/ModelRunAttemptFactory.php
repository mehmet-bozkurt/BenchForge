<?php

namespace Database\Factories;

use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelRunAttempt>
 */
class ModelRunAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model_run_id' => ModelRun::factory(),
            'attempt_number' => 1,
            'mode' => 'continue',
            'status' => 'completed',
            'source_session_id' => 'ses_'.fake()->regexify('[A-Za-z0-9]{24}'),
            'session_id' => 'ses_'.fake()->regexify('[A-Za-z0-9]{24}'),
            'result' => [
                'mode' => 'continue',
                'durationSeconds' => 60,
                'overallStatus' => 'pass',
                'failedStages' => [],
                'stages' => [],
            ],
            'message' => 'Kurtarma denemesi tamamlandı.',
            'prompt' => 'Eksik işi kaldığın yerden tamamla.',
            'state_path' => '/tmp/benchforge-attempt/state.json',
            'log_path' => '/tmp/benchforge-attempt/runner.log',
            'attempt_path' => '/tmp/benchforge-attempt',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
