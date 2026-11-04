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
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('template_id', 64);
            $table->unsignedInteger('template_version');
            $table->char('template_sha256', 64);
            $table->string('title', 120);
            $table->string('status', 20)->default('starting')->index();
            $table->json('inputs');
            $table->json('settings');
            $table->string('working_directory', 1024);
            $table->unsignedInteger('runner_pid')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
