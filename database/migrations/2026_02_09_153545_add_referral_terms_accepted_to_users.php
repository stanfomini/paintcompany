<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('referral_terms_accepted_at')->nullable()->after('referral_payment_handle');
            $table->string('referral_terms_ip_address')->nullable()->after('referral_terms_accepted_at');
            $table->string('referral_terms_initials', 10)->nullable()->after('referral_terms_ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['referral_terms_accepted_at', 'referral_terms_ip_address', 'referral_terms_initials']);
        });
    }
};
