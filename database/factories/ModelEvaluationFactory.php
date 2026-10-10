<?php

namespace Database\Factories;

use App\Models\ModelEvaluation;
use App\Models\ModelRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelEvaluation>
 */
class ModelEvaluationFactory extends Factory
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
            'visual_design_score' => fake()->numberBetween(5, 10),
            'user_experience_score' => fake()->numberBetween(5, 10),
            'task_completion_score' => fake()->numberBetween(5, 10),
            'functionality_score' => fake()->numberBetween(5, 10),
            'responsive_quality_score' => fake()->numberBetween(5, 10),
            'content_quality_score' => fake()->numberBetween(5, 10),
            'consistency_score' => fake()->numberBetween(5, 10),
            'overall_score' => fake()->numberBetween(5, 10),
            'issue_severity' => 'none',
            'notes' => null,
            'reviewed_at' => now(),
        ];
    }
}
