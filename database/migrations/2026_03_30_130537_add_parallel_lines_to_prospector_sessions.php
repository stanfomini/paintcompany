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
        Schema::table('prospector_dialer_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('parallel_lines')->default(1)->after('mode');
            $table->string('current_batch_id', 26)->nullable()->after('parallel_lines');
        });

        Schema::table('prospector_calls', function (Blueprint $table) {
            $table->string('batch_id', 26)->nullable()->after('campaign_id');
            $table->index('batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospector_dialer_sessions', function (Blueprint $table) {
            $table->dropColumn(['parallel_lines', 'current_batch_id']);
        });

        Schema::table('prospector_calls', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
