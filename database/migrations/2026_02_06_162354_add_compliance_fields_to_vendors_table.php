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
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('w9_on_file')->default(false)->after('notes');
            $table->boolean('hold_harmless')->default(false)->after('w9_on_file');
            $table->boolean('certificate_of_insurance')->default(false)->after('hold_harmless');
            $table->date('insurance_expiration')->nullable()->after('certificate_of_insurance');
            $table->boolean('additional_insured')->default(false)->after('insurance_expiration');
            $table->boolean('workers_comp')->default(false)->after('additional_insured');
            $table->date('workers_comp_expiration')->nullable()->after('workers_comp');
            $table->boolean('rejection_form')->default(false)->after('workers_comp_expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'w9_on_file',
                'hold_harmless',
                'certificate_of_insurance',
                'insurance_expiration',
                'additional_insured',
                'workers_comp',
                'workers_comp_expiration',
                'rejection_form',
            ]);
        });
    }
};
