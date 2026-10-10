<?php

namespace App\Models;

use Database\Factories\ModelEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelEvaluation extends Model
{
    /** @use HasFactory<ModelEvaluationFactory> */
    use HasFactory;

    protected $fillable = [
        'visual_design_score',
        'user_experience_score',
        'task_completion_score',
        'functionality_score',
        'responsive_quality_score',
        'content_quality_score',
        'consistency_score',
        'overall_score',
        'issue_severity',
        'notes',
        'reviewed_at',
    ];

    /**
     * @return BelongsTo<ModelRun, $this>
     */
    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
