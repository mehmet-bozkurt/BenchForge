<?php

namespace App\Models;

use Database\Factories\StageResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageResult extends Model
{
    /** @use HasFactory<StageResultFactory> */
    use HasFactory;

    /**
     * Model-step exit code for a provider-side failure (server error, capacity); the run is not scored.
     */
    public const PROVIDER_ERROR_CODE = 75;

    /**
     * Model-step exit code for a provider failure that came after the model had already changed the workspace.
     * The submission is validated and scored like a normal one; the code only records the interrupted connection.
     */
    public const INTERRUPTED_AFTER_SUBMISSION_CODE = 77;

    /**
     * Model-step exit code for a model that changed nothing in the starting template.
     */
    public const NO_SUBMISSION_CODE = 86;

    public function isProviderError(): bool
    {
        return $this->stage === 'opencode' && $this->exit_code === self::PROVIDER_ERROR_CODE;
    }

    protected $fillable = [
        'stage',
        'status',
        'exit_code',
        'raw_value',
        'message',
        'log_path',
        'log_size_bytes',
    ];

    /**
     * @return BelongsTo<ModelRun, $this>
     */
    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class);
    }
}
