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
        Schema::create('production_plan_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->integer('week_number'); // 1-52

            // Revenue tracking
            $table->decimal('revenue_plan', 12, 2)->default(0);
            $table->decimal('revenue_actual', 12, 2)->default(0);

            // Jobs completed tracking
            $table->integer('jobs_completed_plan')->default(0);
            $table->integer('jobs_completed_actual')->default(0);

            // Labor hours tracking
            $table->decimal('labor_hours_plan', 10, 2)->default(0);
            $table->decimal('labor_hours_actual', 10, 2)->default(0);

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
        Schema::dropIfExists('production_plan_weeks');
    }
};
