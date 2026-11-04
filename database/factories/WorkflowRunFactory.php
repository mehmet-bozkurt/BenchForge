<?php

namespace Database\Factories;

use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'game-idea-factory-'.fake()->unique()->numerify('20261022-1#####');

        return [
            'slug' => $slug,
            'template_id' => 'game-idea-factory',
            'template_version' => 1,
            'template_sha256' => hash('sha256', 'fixture-workflow-template'),
            'title' => 'Oyun Fikir Fabrikası',
            'status' => 'completed',
            'inputs' => ['ideasPerCategory' => 1, 'docLanguage' => 'Türkçe', 'extraBrief' => ''],
            'settings' => [
                'maxParallelModels' => 1,
                'maxConcurrentCalls' => 2,
                'stepModes' => ['build' => 'code_text'],
                'timeouts' => ['llm' => null, 'code' => null],
            ],
            'working_directory' => storage_path('framework/testing/workflow-runs-missing/'.$slug),
            'runner_pid' => null,
            'message' => 'İş akışı tamamlandı.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
