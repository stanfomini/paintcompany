<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->longText('terms_and_conditions_es')->nullable()->after('terms_and_conditions');
            $table->longText('privacy_policy_es')->nullable()->after('privacy_policy');
            $table->longText('referral_terms_es')->nullable()->after('referral_terms');
            $table->longText('crew_colorado_compliance_contract_es')->nullable()->after('crew_colorado_compliance_contract');
            $table->longText('crew_contractor_agreement_es')->nullable()->after('crew_contractor_agreement');
            $table->longText('crew_background_check_consent_es')->nullable()->after('crew_background_check_consent');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'terms_and_conditions_es',
                'privacy_policy_es',
                'referral_terms_es',
                'crew_colorado_compliance_contract_es',
                'crew_contractor_agreement_es',
                'crew_background_check_consent_es',
            ]);
        });
    }
};
