<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_stubs', function (Blueprint $table) {
            $table->decimal('commission_amount', 12, 2)->default(0)->after('hours_pto');
            $table->json('commission_details')->nullable()->after('commission_amount');
            $table->decimal('ytd_commission', 12, 2)->default(0)->after('ytd_medicare');
        });
    }

    public function down(): void
    {
        Schema::table('pay_stubs', function (Blueprint $table) {
            $table->dropColumn(['commission_amount', 'commission_details', 'ytd_commission']);
        });
    }
};
