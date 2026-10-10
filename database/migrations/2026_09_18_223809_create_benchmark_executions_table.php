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
        Schema::create('benchmark_executions', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('status', 24)->default('starting');
            $table->string('prompt_path');
            $table->string('config_path');
            $table->json('routes');
            $table->unsignedInteger('model_count');
            $table->unsignedInteger('completed_model_count')->default(0);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('phase')->default('Başlatılıyor');
            $table->text('message')->nullable();
            $table->unsignedBigInteger('runner_pid')->nullable();
            $table->unsignedBigInteger('benchmark_pid')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('state_path');
            $table->text('log_path');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benchmark_executions');
    }
};
