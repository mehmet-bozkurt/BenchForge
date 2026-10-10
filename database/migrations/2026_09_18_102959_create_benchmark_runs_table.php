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
        Schema::create('benchmark_runs', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('source_path');
            $table->char('source_fingerprint', 64);
            $table->string('schema', 24);
            $table->timestamp('started_at')->nullable();
            $table->json('metadata')->nullable();
            $table->json('routes')->nullable();
            $table->json('stage_names');
            $table->unsignedInteger('model_count')->default(0);
            $table->unsignedInteger('passed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('unknown_count')->default(0);
            $table->unsignedInteger('total_duration_seconds')->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['schema', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benchmark_runs');
    }
};
