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
        // Sales plan goals - annual targets
        Schema::create('sales_plan_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->decimal('annual_revenue_goal', 12, 2)->default(0);
            $table->decimal('avg_job_size', 10, 2)->default(0);
            $table->decimal('lead_conversion_rate', 5, 2)->default(0); // percentage
            $table->decimal('estimate_close_rate', 5, 2)->default(0); // percentage
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_setting_id', 'year']);
        });

        // Sales plan weeks - weekly plan vs actual tracking
        Schema::create('sales_plan_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->integer('week_number'); // 1-52

            // Leads tracking
            $table->integer('leads_plan')->default(0);
            $table->integer('leads_actual')->default(0);

            // Estimates tracking
            $table->integer('estimates_plan')->default(0);
            $table->integer('estimates_actual')->default(0);

            // Jobs booked tracking
            $table->integer('jobs_booked_plan')->default(0);
            $table->integer('jobs_booked_actual')->default(0);

            // Revenue tracking
            $table->decimal('revenue_plan', 12, 2)->default(0);
            $table->decimal('revenue_actual', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable(); // Last auto-sync from app data
            $table->timestamps();

            $table->unique(['company_setting_id', 'year', 'week_number']);
            $table->index(['year', 'week_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_plan_weeks');
        Schema::dropIfExists('sales_plan_goals');
    }
};
