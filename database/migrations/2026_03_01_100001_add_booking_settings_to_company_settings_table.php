<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('booking_enabled')->default(false)->after('logo_path');
            $table->unsignedInteger('booking_slot_duration_minutes')->default(60)->after('booking_enabled');
            $table->unsignedInteger('booking_buffer_minutes')->default(15)->after('booking_slot_duration_minutes');
            $table->unsignedInteger('booking_lookahead_days')->default(30)->after('booking_buffer_minutes');
            $table->unsignedInteger('booking_min_notice_hours')->default(24)->after('booking_lookahead_days');
            $table->json('bookable_estimator_ids')->nullable()->after('booking_min_notice_hours');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'booking_enabled',
                'booking_slot_duration_minutes',
                'booking_buffer_minutes',
                'booking_lookahead_days',
                'booking_min_notice_hours',
                'bookable_estimator_ids',
            ]);
        });
    }
};
