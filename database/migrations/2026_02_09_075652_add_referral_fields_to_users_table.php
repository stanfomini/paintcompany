<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 8)->unique()->nullable()->after('calendar_token');
            $table->string('referral_payment_method', 20)->nullable()->after('referral_code');
            $table->string('referral_payment_handle', 100)->nullable()->after('referral_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referral_payment_method', 'referral_payment_handle']);
        });
    }
};
