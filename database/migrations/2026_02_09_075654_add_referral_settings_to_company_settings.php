<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('referral_reward_amount', 8, 2)->default(50.00)->after('payment_split_config');
            $table->boolean('referral_program_enabled')->default(true)->after('referral_reward_amount');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['referral_reward_amount', 'referral_program_enabled']);
        });
    }
};
