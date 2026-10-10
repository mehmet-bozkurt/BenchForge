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
        Schema::create('preparation_comparisons', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->longText('specification');
            $table->text('technologies');
            $table->string('provider', 32);
            $table->json('selected_models');
            $table->string('source_hash', 64);
            $table->string('contract_version', 16)->default('v1');
            $table->string('status', 16)->default('starting')->index();
            $table->text('working_directory')->nullable();
            $table->unsignedInteger('runner_pid')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preparation_comparisons');
    }
};
