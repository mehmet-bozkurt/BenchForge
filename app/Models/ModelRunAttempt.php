<?php

namespace App\Models;

use Database\Factories\ModelRunAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelRunAttempt extends Model
{
    /** @use HasFactory<ModelRunAttemptFactory> */
    use HasFactory;

    public const ACTIVE_STATUSES = ['starting', 'running', 'stopping'];

    protected $fillable = [
        'attempt_number',
        'mode',
        'status',
        'source_session_id',
        'session_id',
        'result',
        'usage',
        'message',
        'prompt',
        'runner_pid',
        'process_pid',
        'exit_code',
        'state_path',
        'log_path',
        'attempt_path',
        'started_at',
        'finished_at',
        'synced_at',
    ];

    /** @return BelongsTo<ModelRun, $this> */
    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result' => 'array',
            'usage' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
