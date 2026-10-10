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
        Schema::table('model_runs', function (Blueprint $table) {
            $table->json('usage')->nullable();
        });

        Schema::table('model_run_attempts', function (Blueprint $table) {
            $table->json('usage')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('model_run_attempts', function (Blueprint $table) {
            $table->dropColumn('usage');
        });

        Schema::table('model_runs', function (Blueprint $table) {
            $table->dropColumn('usage');
        });
    }
};
