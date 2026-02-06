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
            $table->string('workers_comp_carrier')->nullable()->after('insurance_photo_type');
            $table->string('workers_comp_policy_number')->nullable()->after('workers_comp_carrier');
            $table->date('workers_comp_start_date')->nullable()->after('workers_comp_policy_number');
            $table->date('workers_comp_expiration_date')->nullable()->after('workers_comp_start_date');
            $table->string('workers_comp_document')->nullable()->after('workers_comp_expiration_date');
            $table->string('workers_comp_document_type')->nullable()->after('workers_comp_document');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crew_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'workers_comp_carrier',
                'workers_comp_policy_number',
                'workers_comp_start_date',
                'workers_comp_expiration_date',
                'workers_comp_document',
                'workers_comp_document_type',
            ]);
        });
    }
};
