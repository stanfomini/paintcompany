<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('welcome_sms_enabled')->default(true)->after('sms_bot_enabled');
            $table->text('welcome_sms_with_deal')->nullable()->after('welcome_sms_enabled');
            $table->text('welcome_sms_without_deal')->nullable()->after('welcome_sms_with_deal');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('welcome_sms_sent_at')->nullable()->after('sms_marketing_consent_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('welcome_sms_sent_at')->nullable()->after('sms_consented_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['welcome_sms_enabled', 'welcome_sms_with_deal', 'welcome_sms_without_deal']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('welcome_sms_sent_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('welcome_sms_sent_at');
        });
    }
};
