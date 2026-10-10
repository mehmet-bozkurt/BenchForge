<?php

namespace App\Models;

use Database\Factories\PreparationPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationPackage extends Model
{
    /** @use HasFactory<PreparationPackageFactory> */
    use HasFactory;

    public const SECTION_LABELS = [
        'startup_prompt' => 'Başlangıç promptu',
        'requirements' => 'Gereksinimler ve kabul kriterleri',
        'implementation_plan' => 'Geliştirme planı ve görev promptları',
        'memory_bank' => 'Başlangıç hafıza bankası',
        'assumptions_risks' => 'Varsayımlar, belirsizlikler ve riskler',
    ];

    public const EVALUATION_LABELS = [
        'coverage_score' => 'Şartname kapsamı',
        'feasibility_score' => 'Uygulanabilirlik',
        'clarity_score' => 'Açıklık ve tutarlılık',
        'assumption_management_score' => 'Varsayım ve kapsam yönetimi',
        'overall_score' => 'Genel değerlendirme',
    ];

    protected $fillable = [
        'model_id', 'model_name', 'status', 'startup_prompt', 'requirements',
        'implementation_plan', 'memory_bank', 'assumptions_risks', 'structural_checks',
        'error_message', 'duration_seconds', 'cost_usd', 'input_tokens', 'output_tokens',
        'manual_evaluation', 'review_notes', 'issue_severity', 'reviewed_at',
    ];

    /** @return BelongsTo<PreparationComparison, $this> */
    public function preparationComparison(): BelongsTo
    {
        return $this->belongsTo(PreparationComparison::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'structural_checks' => 'array',
            'manual_evaluation' => 'array',
            'duration_seconds' => 'decimal:2',
            'cost_usd' => 'decimal:8',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
