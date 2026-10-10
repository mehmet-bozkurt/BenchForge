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
        Schema::table('open_router_models', function (Blueprint $table) {
            $table->json('catalog_payload')->nullable();
        });

        Schema::table('open_code_go_models', function (Blueprint $table) {
            $table->json('catalog_payload')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('open_router_models', function (Blueprint $table) {
            $table->dropColumn('catalog_payload');
        });

        Schema::table('open_code_go_models', function (Blueprint $table) {
            $table->dropColumn('catalog_payload');
        });
    }
};
