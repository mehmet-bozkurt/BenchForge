<?php

namespace Database\Factories;

use App\Models\WorkflowRun;
use App\Models\WorkflowRunModel;
use App\Services\ModelSelection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRunModel>
 */
class WorkflowRunModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_run_id' => WorkflowRun::factory(),
            'model' => 'openrouter/fixture/'.fake()->unique()->slug(2),
            'model_name' => 'Örnek iş akışı modeli',
            'slug' => fn (array $attributes): string => ModelSelection::folderFor($attributes['model']),
            'provider' => fn (array $attributes): string => str($attributes['model'])->before('/')->toString(),
            'status' => 'completed',
            'error_message' => null,
            'duration_seconds' => 42,
            'input_tokens' => 1200,
            'output_tokens' => 3400,
            'cost_usd' => 0,
            'items_total' => 4,
            'items_completed' => 4,
            'items_failed' => 0,
        ];
    }
}
