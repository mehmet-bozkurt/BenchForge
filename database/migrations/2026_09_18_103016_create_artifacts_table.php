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
        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benchmark_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('artifact_key');
            $table->string('kind', 24);
            $table->string('type', 64);
            $table->string('label');
            $table->text('path');
            $table->text('relative_path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->timestamps();

            $table->unique(['benchmark_run_id', 'artifact_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
