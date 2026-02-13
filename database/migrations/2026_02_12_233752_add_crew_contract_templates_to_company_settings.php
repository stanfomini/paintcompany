<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Crew contract templates
            $table->longText('crew_colorado_compliance_contract')->nullable()->after('referral_terms');
            $table->longText('crew_contractor_agreement')->nullable()->after('crew_colorado_compliance_contract');
            $table->longText('crew_background_check_consent')->nullable()->after('crew_contractor_agreement');
            // Crew pitch page settings
            $table->decimal('crew_pitch_hourly_rate', 8, 2)->nullable()->after('crew_background_check_consent');
            $table->unsignedInteger('crew_pitch_job_sqft')->nullable()->after('crew_pitch_hourly_rate');
            $table->decimal('crew_pitch_job_price', 10, 2)->nullable()->after('crew_pitch_job_sqft');
            $table->decimal('crew_pitch_paint_cost', 10, 2)->nullable()->after('crew_pitch_job_price');
            $table->decimal('crew_pitch_bonus_percent', 5, 2)->nullable()->after('crew_pitch_paint_cost');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'crew_colorado_compliance_contract',
                'crew_contractor_agreement',
                'crew_background_check_consent',
                'crew_pitch_hourly_rate',
                'crew_pitch_job_sqft',
                'crew_pitch_job_price',
                'crew_pitch_paint_cost',
                'crew_pitch_bonus_percent',
            ]);
        });
    }
};
