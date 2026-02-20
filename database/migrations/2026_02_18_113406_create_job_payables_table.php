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
        Schema::create('job_payables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('painting_jobs')->cascadeOnDelete();
            $table->foreignId('job_cost_card_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee_type'); // crew, vendor, employee, contractor
            $table->foreignId('payee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee_name');
            $table->foreignId('service_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category'); // labor, materials, sundries, tax, cleanup, addon, commission, flat_rate, bonus, subcontractor, other
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending'); // pending, approved, paid, void
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_via')->nullable(); // check, ach, cash, stripe, other
            $table->string('reference_number')->nullable();
            $table->foreignId('debit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'status']);
            $table->index(['payee_user_id', 'status']);
            $table->index(['vendor_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_payables');
    }
};
