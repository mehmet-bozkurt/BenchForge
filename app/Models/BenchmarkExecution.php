<?php

namespace App\Models;

use Database\Factories\BenchmarkExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BenchmarkExecution extends Model
{
    /** @use HasFactory<BenchmarkExecutionFactory> */
    use HasFactory;

    public const ACTIVE_STATUSES = ['starting', 'running', 'stopping'];

    protected $fillable = [
        'run_id',
        'status',
        'prompt_path',
        'config_path',
        'routes',
        'model_count',
        'completed_model_count',
        'progress_percent',
        'phase',
        'message',
        'runner_pid',
        'benchmark_pid',
        'exit_code',
        'state_path',
        'log_path',
        'started_at',
        'finished_at',
        'synced_at',
    ];

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'routes' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
