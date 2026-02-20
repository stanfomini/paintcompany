<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_bank_account')->default(false)->after('is_active');
        });

        Schema::table('job_payables', function (Blueprint $table) {
            $table->foreignId('paid_from_account_id')->nullable()->after('credit_account_id');

            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('paid_from_account_id')->references('id')->on('accounts')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_payables', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['paid_from_account_id']);
            }
            $table->dropColumn('paid_from_account_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_bank_account');
        });
    }
};
