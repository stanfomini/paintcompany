<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('source');
            $table->string('source_label')->nullable();
            $table->string('commission_type'); // revenue_percentage, profit_percentage, flat_per_job, per_lead, tiered
            $table->decimal('basis_amount', 12, 2)->default(0);
            $table->decimal('rate', 8, 4)->default(0);
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending'); // pending, approved, paid, reversed
            $table->date('earned_date');
            $table->foreignId('pay_period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pay_stub_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_profile_id', 'status']);
            $table->index(['status', 'earned_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
    }
};
