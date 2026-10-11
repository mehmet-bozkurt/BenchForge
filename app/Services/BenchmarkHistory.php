<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\StageResult;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Throwable;

class BenchmarkHistory
{
    private const MAX_PROMPT_BYTES = 262144;

    /**
     * The delivery contract BenchForge appends to custom prompts; it is not part of the task description.
     */
    private const CONTRACT_MARKER = 'BenchForge teslim sözleşmesi';

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return BenchmarkRun::query()
            ->with([
                'modelRuns' => fn (HasMany $relation): HasMany => $relation->oldest('id'),
                'modelRuns.stageResults' => fn (HasMany $relation): HasMany => $relation->oldest('id'),
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BenchmarkRun $run): array => [
                'slug' => $run->slug,
                'name' => $run->name,
                'date' => $run->started_at ?? $run->created_at,
                'schema' => $run->schema,
                'stageNames' => $run->stage_names,
                'environmentProfile' => $run->environmentProfile(),
                'taskSummary' => $this->taskSummary($run),
                'routes' => array_values(array_filter($run->routes ?? [], 'is_string')),
                'costUsd' => $this->totalCost($run),
                'models' => $run->modelRuns->map(fn (ModelRun $modelRun): array => [
                    'slug' => $modelRun->slug,
                    'model' => $modelRun->model,
                    'displayName' => Str::after($modelRun->model, '/'),
                    'durationSeconds' => $modelRun->duration_seconds,
                    'overallStatus' => $modelRun->status,
                    'failedStages' => $modelRun->failed_stages ?? [],
                    'stages' => $modelRun->stageResults
                        ->mapWithKeys(fn (StageResult $stageResult): array => [
                            $stageResult->stage => [
                                'status' => $stageResult->status,
                                'code' => $stageResult->exit_code,
                                'rawValue' => $stageResult->raw_value,
                                'message' => $stageResult->message,
                                'logPath' => $stageResult->log_path,
                                'logSizeBytes' => $stageResult->log_size_bytes,
                            ],
                        ])
                        ->all(),
                ])->all(),
                'counts' => [
                    'pass' => $run->passed_count,
                    'fail' => $run->failed_count,
                    'skip' => $run->skipped_count,
                    'unknown' => $run->unknown_count,
                    'total' => $run->model_count,
                ],
                'totalDurationSeconds' => $run->total_duration_seconds,
                'storage' => [
                    'totalBytes' => $run->total_bytes,
                    'workspaceBytes' => $run->workspace_bytes,
                    'resultBytes' => $run->result_bytes,
                    'workspaceCount' => $run->workspace_count,
                    'scannedAt' => $run->storage_scanned_at,
                ],
            ])
            ->all();
    }

    /**
     * The first line of the task prompt, without the appended delivery contract.
     */
    private function taskSummary(BenchmarkRun $run): ?string
    {
        $path = rtrim((string) $run->source_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'_prompt.txt';

        try {
            if ($run->source_path === null || ! is_file($path) || is_link($path) || filesize($path) > self::MAX_PROMPT_BYTES) {
                return null;
            }

            $prompt = Str::before((string) file_get_contents($path), self::CONTRACT_MARKER);
        } catch (Throwable) {
            return null;
        }

        return $this->summarizePrompt($prompt);
    }

    /**
     * The first meaningful sentence of a prompt, skipping generic benchmark preambles.
     */
    public function summarizePrompt(string $prompt): ?string
    {
        $paragraphs = collect(preg_split('/\R\s*\R/u', $prompt) ?: [])
            ->map(fn (string $paragraph): string => trim((string) preg_replace('/\s+/u', ' ', $paragraph)))
            ->reject(fn (string $paragraph): bool => $paragraph === '' || $paragraph === '---' || preg_match('/^you are participating in/iu', $paragraph) === 1)
            ->values();

        if ($paragraphs->isEmpty()) {
            return null;
        }

        $summary = $paragraphs->first();

        if (str_ends_with($summary, ':') && $paragraphs->has(1)) {
            $summary .= ' '.$paragraphs->get(1);
        }

        $summary = (string) preg_replace([
            '/^your task is to transform the existing next\.js project into (an? )?/iu',
            '/^bu klasördeki next\.js başlangıç şablonu(nun)? üzerinde,?\s*/iu',
        ], '', $summary);

        if (str_contains($summary, '. ')) {
            $summary = Str::before($summary, '. ').'.';
        }

        return $summary === '' ? null : Str::limit($this->capitalize($summary), 150);
    }

    /**
     * Uppercase the first letter, using the Turkish dotted İ for Turkish text.
     */
    private function capitalize(string $text): string
    {
        $first = mb_substr($text, 0, 1);
        $isTurkish = preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $text) === 1;
        $upper = $first === 'i' && $isTurkish ? 'İ' : mb_strtoupper($first);

        return $upper.mb_substr($text, 1);
    }

    /**
     * Total recorded model cost; null when no model of the run has a recorded cost.
     */
    private function totalCost(BenchmarkRun $run): ?float
    {
        $costs = $run->modelRuns
            ->map(fn (ModelRun $modelRun): mixed => $modelRun->usage['costUsd'] ?? null)
            ->filter(fn (mixed $cost): bool => is_numeric($cost));

        return $costs->isEmpty() ? null : (float) $costs->sum(fn (mixed $cost): float => (float) $cost);
    }
}
