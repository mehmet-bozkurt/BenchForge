<?php

namespace App\Models;

use Database\Factories\BenchmarkRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenchmarkRun extends Model
{
    /** @use HasFactory<BenchmarkRunFactory> */
    use HasFactory;

    /**
     * The model working condition recorded in the run's `_meta.txt`; runs without it predate isolation.
     */
    public const CURRENT_ENVIRONMENT_PROFILE = 'isolated-v1';

    /** @var array<string, string> */
    public const ENVIRONMENT_PROFILES = [
        'isolated-v1' => 'Yalıtılmış koşul v1',
        'legacy' => 'Eski koşul',
        'manual' => 'Harness dışı',
    ];

    protected $fillable = [
        'slug',
        'name',
        'source_path',
        'source_fingerprint',
        'schema',
        'started_at',
        'metadata',
        'routes',
        'stage_names',
        'model_count',
        'passed_count',
        'failed_count',
        'skipped_count',
        'unknown_count',
        'total_duration_seconds',
        'total_bytes',
        'workspace_bytes',
        'result_bytes',
        'workspace_count',
        'storage_scanned_at',
        'workspace_deleted_at',
        'synced_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function environmentProfile(): string
    {
        $profile = $this->metadata['environment_profile'] ?? null;

        return is_string($profile) && array_key_exists($profile, self::ENVIRONMENT_PROFILES) ? $profile : 'legacy';
    }

    /**
     * @return HasMany<ModelRun, $this>
     */
    public function modelRuns(): HasMany
    {
        return $this->hasMany(ModelRun::class);
    }

    /**
     * @return HasMany<Artifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'metadata' => 'array',
            'routes' => 'array',
            'stage_names' => 'array',
            'storage_scanned_at' => 'immutable_datetime',
            'workspace_deleted_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
