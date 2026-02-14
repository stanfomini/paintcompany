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
        Schema::table('crew_profiles', function (Blueprint $table) {
            $table->dateTime('onboarding_call_scheduled_at')->nullable()->after('had_onboarding_call');
            $table->text('onboarding_call_notes')->nullable()->after('onboarding_call_scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crew_profiles', function (Blueprint $table) {
            $table->dropColumn(['onboarding_call_scheduled_at', 'onboarding_call_notes']);
        });
    }
};
