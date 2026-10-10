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
        Schema::create('model_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('visual_design_score');
            $table->unsignedTinyInteger('user_experience_score');
            $table->unsignedTinyInteger('task_completion_score');
            $table->unsignedTinyInteger('functionality_score');
            $table->unsignedTinyInteger('responsive_quality_score');
            $table->unsignedTinyInteger('content_quality_score');
            $table->unsignedTinyInteger('consistency_score');
            $table->unsignedTinyInteger('overall_score');
            $table->string('issue_severity', 16)->default('none');
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_evaluations');
    }
};
