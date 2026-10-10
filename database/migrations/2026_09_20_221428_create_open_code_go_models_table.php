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
        Schema::create('open_code_go_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_id')->unique();
            $table->string('name');
            $table->string('family')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('context_length')->nullable();
            $table->unsignedInteger('output_limit')->nullable();
            $table->decimal('input_cost', 14, 6)->nullable();
            $table->decimal('output_cost', 14, 6)->nullable();
            $table->decimal('cache_read_cost', 14, 6)->nullable();
            $table->decimal('cache_write_cost', 14, 6)->nullable();
            $table->json('reasoning_options')->nullable();
            $table->json('modalities')->nullable();
            $table->boolean('agent_compatible')->default(false);
            $table->boolean('supports_reasoning')->default(false);
            $table->boolean('supports_attachments')->default(false);
            $table->boolean('is_free')->default(false);
            $table->boolean('is_preview')->default(false);
            $table->boolean('available')->default(true);
            $table->date('released_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['available', 'agent_compatible']);
            $table->index(['family', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_code_go_models');
    }
};
