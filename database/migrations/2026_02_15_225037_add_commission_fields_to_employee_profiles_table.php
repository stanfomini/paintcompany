<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->json('commission_rules')->nullable()->after('pay_type');
            $table->string('commission_tax_method')->default('aggregate')->after('commission_rules');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn(['commission_rules', 'commission_tax_method']);
        });
    }
};
