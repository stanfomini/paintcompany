<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_profiles', function (Blueprint $table) {
            $table->boolean('had_onboarding_call')->default(false)->after('notes');
            $table->timestamp('onboarding_completed_at')->nullable()->after('had_onboarding_call');
            $table->string('drivers_license_front')->nullable()->after('onboarding_completed_at');
            $table->string('drivers_license_back')->nullable()->after('drivers_license_front');
            $table->foreignId('onboarding_call_confirmed_by')->nullable()->constrained('users')->nullOnDelete()->after('drivers_license_back');
            $table->timestamp('onboarding_call_confirmed_at')->nullable()->after('onboarding_call_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('crew_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('onboarding_call_confirmed_by');
            $table->dropColumn([
                'had_onboarding_call',
                'onboarding_completed_at',
                'drivers_license_front',
                'drivers_license_back',
                'onboarding_call_confirmed_at',
            ]);
        });
    }
};
