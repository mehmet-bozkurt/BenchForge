<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('preparation_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preparation_comparison_id')->constrained()->cascadeOnDelete();
            $table->string('model_id', 200);
            $table->string('model_name', 255);
            $table->string('status', 16)->default('pending');
            $table->longText('startup_prompt')->nullable();
            $table->longText('requirements')->nullable();
            $table->longText('implementation_plan')->nullable();
            $table->longText('memory_bank')->nullable();
            $table->longText('assumptions_risks')->nullable();
            $table->json('structural_checks')->nullable();
            $table->text('error_message')->nullable();
            $table->decimal('duration_seconds', 12, 2)->nullable();
            $table->decimal('cost_usd', 16, 8)->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->json('manual_evaluation')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('issue_severity', 16)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['preparation_comparison_id', 'model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preparation_packages');
    }
};
