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
        // Life Commitments - personal/business commitments tracker
        Schema::create('life_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->string('area'); // e.g. "Family", "Health", "Financial"
            $table->text('commitment');
            $table->string('action_type', 50)->default('ongoing'); // daily, weekly, monthly, quarterly, annual, ongoing
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Budget Categories - line item definitions for the budget P&L
        Schema::create('budget_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->string('section', 50); // revenue, variable_expense, vehicle_expense, business_expense, marketing_expense
            $table->string('name');
            $table->string('slug');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->string('sync_source')->nullable(); // future: quickbooks, xero, etc.
            $table->timestamps();

            $table->unique(['company_setting_id', 'slug']);
        });

        // Budget Line Items - annual plan vs actual amounts per category
        Schema::create('budget_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_category_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->decimal('prior_year_actual', 12, 2)->default(0);
            $table->decimal('current_year_plan', 12, 2)->default(0);
            $table->decimal('current_year_actual', 12, 2)->default(0);
            $table->string('sync_source')->nullable(); // future: quickbooks, xero, etc.
            $table->timestamp('synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_setting_id', 'budget_category_id', 'year'], 'budget_line_items_unique');
        });

        // Overhead Entries - monthly overhead expense tracking
        Schema::create('overhead_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_category_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->integer('month'); // 1-12
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_setting_id', 'budget_category_id', 'year', 'month'], 'overhead_entries_unique');
        });

        // Marketing Tracking Entries - per-source marketing ROI tracking
        Schema::create('marketing_tracking_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->string('source_name');
            $table->decimal('marketing_expense', 12, 2)->default(0);
            $table->integer('leads')->default(0);
            $table->integer('estimates')->default(0);
            $table->integer('jobs_booked')->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->boolean('is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_setting_id', 'year', 'source_name'], 'marketing_tracking_unique');
        });

        // Job Cost Cards - per-job cost breakdown with manual override support
        Schema::create('job_cost_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained('painting_jobs')->nullOnDelete();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->string('client_name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('production_mgmt_labor', 12, 2)->default(0);
            $table->decimal('subcontractor_cost', 12, 2)->default(0);
            $table->decimal('wc_insurance_burden', 12, 2)->default(0);
            $table->decimal('material_cost', 12, 2)->default(0);
            $table->decimal('sales_commission', 12, 2)->default(0);
            $table->boolean('is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Add lead_source to deals for marketing tracking
        Schema::table('deals', function (Blueprint $table) {
            $table->string('lead_source')->nullable()->after('stage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('lead_source');
        });

        Schema::dropIfExists('job_cost_cards');
        Schema::dropIfExists('marketing_tracking_entries');
        Schema::dropIfExists('overhead_entries');
        Schema::dropIfExists('budget_line_items');
        Schema::dropIfExists('budget_categories');
        Schema::dropIfExists('life_commitments');
    }
};
