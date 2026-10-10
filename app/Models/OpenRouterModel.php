<?php

namespace App\Models;

use Database\Factories\OpenRouterModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OpenRouterModel extends Model
{
    /** @use HasFactory<OpenRouterModelFactory> */
    use HasFactory;

    protected $fillable = [
        'model_id',
        'author',
        'name',
        'description',
        'context_length',
        'prompt_price',
        'completion_price',
        'supported_parameters',
        'architecture',
        'catalog_payload',
        'agent_compatible',
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
            'prompt_price' => 'decimal:12',
            'completion_price' => 'decimal:12',
            'supported_parameters' => 'array',
            'architecture' => 'array',
            'catalog_payload' => 'array',
            'agent_compatible' => 'boolean',
            'is_free' => 'boolean',
            'is_preview' => 'boolean',
            'available' => 'boolean',
            'released_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
