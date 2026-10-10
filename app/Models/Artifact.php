<?php

namespace App\Models;

use Database\Factories\ArtifactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Artifact extends Model
{
    /** @use HasFactory<ArtifactFactory> */
    use HasFactory;

    protected $fillable = [
        'model_run_id',
        'artifact_key',
        'kind',
        'type',
        'label',
        'path',
        'relative_path',
        'size_bytes',
        'modified_at',
    ];

    /**
     * @return BelongsTo<BenchmarkRun, $this>
     */
    public function benchmarkRun(): BelongsTo
    {
        return $this->belongsTo(BenchmarkRun::class);
    }

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
            'modified_at' => 'immutable_datetime',
        ];
    }
}
