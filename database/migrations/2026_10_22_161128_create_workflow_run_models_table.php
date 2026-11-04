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
        Schema::create('workflow_run_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->string('model', 255);
            $table->string('model_name', 255);
            $table->string('slug', 64);
            $table->string('provider', 32);
            $table->string('status', 16)->default('pending');
            $table->text('error_message')->nullable();
            $table->decimal('duration_seconds', 12, 2)->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_completed')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->timestamps();
            $table->unique(['workflow_run_id', 'model']);
            $table->unique(['workflow_run_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_run_models');
    }
};
