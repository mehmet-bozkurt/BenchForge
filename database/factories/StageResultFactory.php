<?php

namespace Database\Factories;

use App\Models\ModelRun;
use App\Models\StageResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageResult>
 */
class StageResultFactory extends Factory
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
            'stage' => fake()->randomElement(['opencode', 'lint', 'build', 'runtime']),
            'status' => 'pass',
            'exit_code' => 0,
            'raw_value' => '0',
            'message' => null,
            'log_path' => null,
            'log_size_bytes' => null,
        ];
    }
}
