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
            // Insurance information
            $table->string('insurance_company')->nullable()->after('bio');
            $table->string('policy_number')->nullable()->after('insurance_company');
            $table->date('insurance_start_date')->nullable()->after('policy_number');
            $table->date('insurance_expiration_date')->nullable()->after('insurance_start_date');
            $table->string('insurance_photo')->nullable()->after('insurance_expiration_date');
            $table->string('insurance_photo_type')->nullable()->after('insurance_photo'); // 'file' or 'url'

            // W9 information
            $table->string('w9_photo')->nullable()->after('insurance_photo_type');
            $table->string('w9_photo_type')->nullable()->after('w9_photo'); // 'file' or 'url'
            $table->date('w9_received_date')->nullable()->after('w9_photo_type');

            // License information
            $table->string('license_number')->nullable()->after('w9_received_date');
            $table->date('license_expiration_date')->nullable()->after('license_number');

            // Internal notes
            $table->text('notes')->nullable()->after('license_expiration_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crew_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'insurance_company',
                'policy_number',
                'insurance_start_date',
                'insurance_expiration_date',
                'insurance_photo',
                'insurance_photo_type',
                'w9_photo',
                'w9_photo_type',
                'w9_received_date',
                'license_number',
                'license_expiration_date',
                'notes',
            ]);
        });
    }
};
