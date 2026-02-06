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
        // KPI Categories (e.g., "Marketing", "Sales", "Production", "Profit")
        Schema::create('kpi_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // KPI Metrics (individual KPIs within a category)
        Schema::create('kpi_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('unit')->default('number'); // number, percent, currency
            $table->string('calculation_type')->default('manual'); // manual, auto_count, auto_sum, auto_percent, formula
            $table->json('calculation_config')->nullable(); // Model, date range, conditions for auto calc
            $table->decimal('target_value', 12, 2)->nullable();
            $table->string('target_period')->default('weekly'); // daily, weekly, monthly, quarterly, yearly
            $table->boolean('higher_is_better')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kpi_category_id', 'slug']);
        });

        // KPI Targets (for manual target entry and historical targets)
        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_metric_id')->constrained()->cascadeOnDelete();
            $table->decimal('target_value', 12, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['kpi_metric_id', 'period_start', 'period_end']);
        });

        // KPI Actuals (for manual entry and caching auto-calculated values)
        Schema::create('kpi_actuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_metric_id')->constrained()->cascadeOnDelete();
            $table->decimal('actual_value', 12, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->boolean('is_manual')->default(false); // true for manual entry, false for auto-calculated
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['kpi_metric_id', 'period_start', 'period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_actuals');
        Schema::dropIfExists('kpi_targets');
        Schema::dropIfExists('kpi_metrics');
        Schema::dropIfExists('kpi_categories');
    }
};
