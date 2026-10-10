<?php

namespace App\Models;

use Database\Factories\ModelRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ModelRun extends Model
{
    /** @use HasFactory<ModelRunFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'model',
        'status',
        'duration_seconds',
        'failed_stages',
        'usage',
    ];

    /**
     * @return BelongsTo<BenchmarkRun, $this>
     */
    public function benchmarkRun(): BelongsTo
    {
        return $this->belongsTo(BenchmarkRun::class);
    }

    /**
     * @return HasMany<StageResult, $this>
     */
    public function stageResults(): HasMany
    {
        return $this->hasMany(StageResult::class);
    }

    /**
     * Recovery attempts stay separate from the canonical first result.
     *
     * @return HasMany<ModelRunAttempt, $this>
     */
    public function modelRunAttempts(): HasMany
    {
        return $this->hasMany(ModelRunAttempt::class);
    }

    /**
     * @return HasMany<Artifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    /**
     * @return HasOne<ModelEvaluation, $this>
     */
    public function evaluation(): HasOne
    {
        return $this->hasOne(ModelEvaluation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failed_stages' => 'array',
            'usage' => 'array',
        ];
    }
}
