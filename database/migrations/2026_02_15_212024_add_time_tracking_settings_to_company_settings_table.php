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
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('pay_period_type')->default('biweekly');
            $table->decimal('overtime_threshold_weekly', 4, 1)->default(40);
            $table->decimal('overtime_multiplier', 3, 2)->default(1.50);
            $table->integer('break_duration_minutes')->default(30);
            $table->integer('break_required_after_hours')->default(6);
            $table->integer('geofence_radius_feet')->default(500);
            $table->text('locationiq_api_key')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'pay_period_type',
                'overtime_threshold_weekly',
                'overtime_multiplier',
                'break_duration_minutes',
                'break_required_after_hours',
                'geofence_radius_feet',
                'locationiq_api_key',
            ]);
        });
    }
};
