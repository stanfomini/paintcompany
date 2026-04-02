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
        Schema::table('prospector_campaign_contact', function (Blueprint $table) {
            $table->unsignedInteger('calls_today')->default(0)->after('attempts');
            $table->unsignedInteger('total_attempts')->default(0)->after('calls_today');
            $table->timestamp('entered_at')->useCurrent()->after('total_attempts');
            $table->timestamp('last_called_at')->nullable()->after('entered_at');
            $table->timestamp('next_callable_at')->nullable()->after('last_called_at');
            $table->timestamp('exhausted_at')->nullable()->after('next_callable_at');

            $table->index(['campaign_id', 'status', 'calls_today', 'next_callable_at'], 'cc_queue_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospector_campaign_contact', function (Blueprint $table) {
            $table->dropIndex('cc_queue_idx');
            $table->dropColumn([
                'calls_today', 'total_attempts', 'entered_at',
                'last_called_at', 'next_callable_at', 'exhausted_at',
            ]);
        });
    }
};
