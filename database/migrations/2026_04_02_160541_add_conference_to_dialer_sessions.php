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
            $table->string('conference_name')->nullable()->after('current_batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospector_dialer_sessions', function (Blueprint $table) {
            $table->dropColumn('conference_name');
        });
    }
};
