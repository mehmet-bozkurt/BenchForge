<?php

namespace App\Models;

use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use HasFactory;

    public const ACTIVE_STATUSES = ['starting', 'running', 'stopping'];

    public const FINAL_STATUSES = ['completed', 'partial', 'failed', 'stopped'];

    protected $fillable = [
        'slug', 'template_id', 'template_version', 'template_sha256', 'title', 'status',
        'inputs', 'settings', 'working_directory', 'runner_pid', 'message', 'started_at', 'finished_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<WorkflowRunModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(WorkflowRunModel::class)->orderBy('id');
    }

    /** @return HasMany<WorkflowRunModel, $this> */
    public function workflowRunModels(): HasMany
    {
        return $this->models();
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'inputs' => 'array',
            'settings' => 'array',
            'runner_pid' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
