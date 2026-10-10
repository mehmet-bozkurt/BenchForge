<?php

namespace App\Models;

use Database\Factories\OpenCodeGoModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OpenCodeGoModel extends Model
{
    /** @use HasFactory<OpenCodeGoModelFactory> */
    use HasFactory;

    protected $fillable = [
        'model_id',
        'name',
        'family',
        'description',
        'context_length',
        'output_limit',
        'input_cost',
        'output_cost',
        'cache_read_cost',
        'cache_write_cost',
        'reasoning_options',
        'modalities',
        'catalog_payload',
        'agent_compatible',
        'supports_reasoning',
        'supports_attachments',
        'is_free',
        'is_preview',
        'available',
        'released_at',
        'synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'input_cost' => 'decimal:6',
            'output_cost' => 'decimal:6',
            'cache_read_cost' => 'decimal:6',
            'cache_write_cost' => 'decimal:6',
            'reasoning_options' => 'array',
            'modalities' => 'array',
            'catalog_payload' => 'array',
            'agent_compatible' => 'boolean',
            'supports_reasoning' => 'boolean',
            'supports_attachments' => 'boolean',
            'is_free' => 'boolean',
            'is_preview' => 'boolean',
            'available' => 'boolean',
            'released_at' => 'immutable_date',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
