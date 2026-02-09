<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('tax_reserve_percent', 5, 2)->default(30.00)->after('accounting_mode');
            $table->decimal('profit_reserve_percent', 5, 2)->default(5.00)->after('tax_reserve_percent');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['tax_reserve_percent', 'profit_reserve_percent']);
        });
    }
};
