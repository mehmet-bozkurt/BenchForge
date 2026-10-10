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
        Schema::create('open_router_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_id')->unique();
            $table->string('author');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('context_length')->nullable();
            $table->decimal('prompt_price', 20, 12)->nullable();
            $table->decimal('completion_price', 20, 12)->nullable();
            $table->json('supported_parameters')->nullable();
            $table->json('architecture')->nullable();
            $table->boolean('agent_compatible')->default(false);
            $table->boolean('is_free')->default(false);
            $table->boolean('is_preview')->default(false);
            $table->boolean('available')->default(true);
            $table->timestamp('released_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['available', 'agent_compatible']);
            $table->index(['author', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_router_models');
    }
};
