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
        Schema::create('pay_stubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained()->cascadeOnDelete();

            // Hours
            $table->decimal('hours_regular', 6, 2)->default(0);
            $table->decimal('hours_overtime', 6, 2)->default(0);
            $table->decimal('hours_pto', 6, 2)->default(0);

            // Rates
            $table->decimal('rate_regular', 10, 2);
            $table->decimal('rate_overtime', 10, 2);

            // Pay
            $table->decimal('gross_pay', 10, 2);

            // Taxes
            $table->decimal('federal_tax', 10, 2)->default(0);
            $table->decimal('state_tax', 10, 2)->default(0);
            $table->decimal('local_tax', 10, 2)->default(0);
            $table->decimal('social_security', 10, 2)->default(0);
            $table->decimal('medicare', 10, 2)->default(0);
            $table->decimal('additional_medicare', 10, 2)->default(0);

            // Deductions
            $table->json('pretax_deductions')->nullable();
            $table->json('posttax_deductions')->nullable();
            $table->decimal('total_deductions', 10, 2)->default(0);

            // Net
            $table->decimal('net_pay', 10, 2);

            // YTD
            $table->decimal('ytd_gross', 12, 2)->default(0);
            $table->decimal('ytd_federal_tax', 12, 2)->default(0);
            $table->decimal('ytd_state_tax', 12, 2)->default(0);
            $table->decimal('ytd_social_security', 12, 2)->default(0);
            $table->decimal('ytd_medicare', 12, 2)->default(0);

            // Meta
            $table->string('pdf_path')->nullable();
            $table->string('check_number')->nullable();
            $table->string('status')->default('draft'); // draft, finalized

            $table->timestamps();

            $table->index(['employee_profile_id', 'pay_period_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pay_stubs');
    }
};
