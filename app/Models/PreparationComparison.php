<?php

namespace App\Models;

use Database\Factories\PreparationComparisonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreparationComparison extends Model
{
    /** @use HasFactory<PreparationComparisonFactory> */
    use HasFactory;

    public const ACTIVE_STATUSES = ['starting', 'running', 'stopping'];

    protected $fillable = [
        'title', 'specification', 'technologies', 'provider', 'selected_models',
        'source_hash', 'contract_version', 'status', 'working_directory',
        'runner_pid', 'started_at', 'finished_at', 'message',
    ];

    /** @return HasMany<PreparationPackage, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(PreparationPackage::class)->orderBy('id');
    }

    /** @return HasMany<PreparationPackage, $this> */
    public function preparationPackages(): HasMany
    {
        return $this->packages();
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'selected_models' => 'array',
            'runner_pid' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
