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
        Schema::table('benchmark_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('total_bytes')->nullable()->after('total_duration_seconds');
            $table->unsignedBigInteger('workspace_bytes')->nullable()->after('total_bytes');
            $table->unsignedBigInteger('result_bytes')->nullable()->after('workspace_bytes');
            $table->unsignedSmallInteger('workspace_count')->default(0)->after('result_bytes');
            $table->timestamp('storage_scanned_at')->nullable()->after('workspace_count');
            $table->timestamp('workspace_deleted_at')->nullable()->after('storage_scanned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('benchmark_runs', function (Blueprint $table) {
            $table->dropColumn([
                'total_bytes',
                'workspace_bytes',
                'result_bytes',
                'workspace_count',
                'storage_scanned_at',
                'workspace_deleted_at',
            ]);
        });
    }
};
