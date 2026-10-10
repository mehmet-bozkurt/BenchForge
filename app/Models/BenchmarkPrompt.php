<?php

namespace App\Models;

use Database\Factories\BenchmarkPromptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BenchmarkPrompt extends Model
{
    /** @use HasFactory<BenchmarkPromptFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'content_hash',
        'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
