<?php

namespace App\Models;

use Database\Factories\WorkflowRunModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRunModel extends Model
{
    /** @use HasFactory<WorkflowRunModelFactory> */
    use HasFactory;

    protected $fillable = [
        'model', 'model_name', 'slug', 'provider', 'status', 'error_message', 'duration_seconds',
        'input_tokens', 'output_tokens', 'cost_usd', 'items_total', 'items_completed', 'items_failed',
    ];

    /** @return BelongsTo<WorkflowRun, $this> */
    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'decimal:2',
            'cost_usd' => 'decimal:6',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'items_total' => 'integer',
            'items_completed' => 'integer',
            'items_failed' => 'integer',
        ];
    }
}
