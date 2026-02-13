<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->longText('terms_and_conditions')->nullable()->after('default_terms_and_conditions');
            $table->longText('privacy_policy')->nullable()->after('terms_and_conditions');
            $table->longText('referral_terms')->nullable()->after('privacy_policy');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['terms_and_conditions', 'privacy_policy', 'referral_terms']);
        });
    }
};
