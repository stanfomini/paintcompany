<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Employee identification
            $table->string('employee_number')->unique();
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('employment_status')->default('active'); // active, on_leave, terminated

            // Pay configuration
            $table->decimal('pay_rate', 10, 2)->nullable();
            $table->string('pay_type')->default('hourly'); // hourly, salary

            // Role / department
            $table->string('department')->nullable();
            $table->string('job_title')->nullable();

            // Personal information
            $table->date('date_of_birth')->nullable();
            $table->text('ssn')->nullable(); // encrypted
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('phone')->nullable();

            // Emergency contact
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_relation')->nullable();

            // Direct deposit (encrypted)
            $table->text('bank_name')->nullable();
            $table->text('bank_routing_number')->nullable(); // encrypted
            $table->text('bank_account_number')->nullable(); // encrypted
            $table->string('bank_account_type')->nullable(); // checking, savings

            // W-4 data
            $table->string('w4_filing_status')->nullable(); // single, married, head_of_household
            $table->integer('w4_allowances')->default(0);
            $table->decimal('w4_additional_withholding', 10, 2)->default(0);

            // Lead-safe certification
            $table->string('lead_safe_cert_number')->nullable();
            $table->date('lead_safe_cert_expiration')->nullable();
            $table->string('lead_safe_cert_document')->nullable();

            // Onboarding
            $table->timestamp('onboarding_completed_at')->nullable();

            // Notes
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
