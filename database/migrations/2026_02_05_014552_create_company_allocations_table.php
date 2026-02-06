<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates flexible allocation system for business cost tracking.
     * Replaces hardcoded marketing/sales/prod_mgmt percentages with
     * dynamic allocations that can be customized per company.
     */
    public function up(): void
    {
        Schema::create('company_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained('company_settings')->cascadeOnDelete();
            $table->string('name');                  // "Marketing", "Growth Loan", "Equipment Fund"
            $table->string('slug');                  // marketing, growth_loan, equipment_fund
            $table->enum('type', [
                'percent_of_revenue',  // e.g., 8% of customer price
                'fixed_per_job',       // e.g., $150 per job
                'per_labor_hour',      // e.g., $5 per labor hour
            ])->default('percent_of_revenue');
            $table->decimal('value', 10, 4);         // 8.00 for 8%, 200.00 for fixed, 5.00/hr
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);  // System allocations can't be deleted
            $table->boolean('is_informational')->default(false);  // Informational = doesn't reduce profit
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Each company can only have one allocation with a given slug
            $table->unique(['company_setting_id', 'slug']);
            $table->index(['company_setting_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_allocations');
    }
};
