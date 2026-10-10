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
        Schema::create('model_run_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('mode', 16);
            $table->string('status', 24)->default('starting');
            $table->string('source_session_id', 80)->nullable();
            $table->string('session_id', 80)->nullable();
            $table->json('result')->nullable();
            $table->text('message')->nullable();
            $table->text('prompt')->nullable();
            $table->unsignedBigInteger('runner_pid')->nullable();
            $table->unsignedBigInteger('process_pid')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('state_path');
            $table->text('log_path');
            $table->text('attempt_path');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['model_run_id', 'attempt_number']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_run_attempts');
    }
};
