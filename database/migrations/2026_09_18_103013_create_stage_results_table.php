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
        Schema::create('stage_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_run_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 64);
            $table->string('status', 24);
            $table->integer('exit_code')->nullable();
            $table->string('raw_value')->nullable();
            $table->text('message')->nullable();
            $table->text('log_path')->nullable();
            $table->unsignedBigInteger('log_size_bytes')->nullable();
            $table->timestamps();

            $table->unique(['model_run_id', 'stage']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stage_results');
    }
};
