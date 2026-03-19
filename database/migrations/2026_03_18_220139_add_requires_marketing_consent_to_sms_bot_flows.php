<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_bot_flows', function (Blueprint $table) {
            $table->boolean('requires_marketing_consent')->default(true)->after('business_hours_only');
        });
    }

    public function down(): void
    {
        Schema::table('sms_bot_flows', function (Blueprint $table) {
            $table->dropColumn('requires_marketing_consent');
        });
    }
};
