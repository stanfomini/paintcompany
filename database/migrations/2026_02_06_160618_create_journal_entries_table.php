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
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->string('entry_number')->unique();
            $table->date('transaction_date')->nullable();
            $table->date('recognition_date')->nullable();
            $table->text('description');
            $table->string('reference_type', 30)->nullable(); // invoice, payment, overhead, payroll, manual
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('status', 20)->default('draft'); // draft, posted, void
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['company_setting_id', 'transaction_date']);
            $table->index(['company_setting_id', 'recognition_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
