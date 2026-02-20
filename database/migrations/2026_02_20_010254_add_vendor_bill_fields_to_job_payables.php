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
        Schema::table('job_payables', function (Blueprint $table) {
            $table->string('vendor_invoice_number')->nullable()->after('reference_number');
            $table->string('attachment_path')->nullable()->after('vendor_invoice_number');
        });

        // Make job_id nullable for standalone vendor bills
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('job_payables', function (Blueprint $table) {
                $table->unsignedBigInteger('job_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_payables', function (Blueprint $table) {
            $table->dropColumn(['vendor_invoice_number', 'attachment_path']);
        });
    }
};
