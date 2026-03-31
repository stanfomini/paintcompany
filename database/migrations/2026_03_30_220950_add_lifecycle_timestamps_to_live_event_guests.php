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
        Schema::table('live_event_guests', function (Blueprint $table) {
            $table->timestamp('disconnected_at')->nullable()->after('went_live_at');
            $table->timestamp('live_ended_at')->nullable()->after('disconnected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('live_event_guests', function (Blueprint $table) {
            $table->dropColumn(['disconnected_at', 'live_ended_at']);
        });
    }
};
