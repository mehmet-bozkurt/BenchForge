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
