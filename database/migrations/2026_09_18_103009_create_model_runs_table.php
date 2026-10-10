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
        Schema::create('model_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benchmark_run_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('model');
            $table->string('status', 24);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->json('failed_stages')->nullable();
            $table->timestamps();

            $table->unique(['benchmark_run_id', 'slug']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_runs');
    }
};
