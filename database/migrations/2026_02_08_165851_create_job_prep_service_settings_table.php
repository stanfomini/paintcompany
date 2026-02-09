<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_prep_service_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_prep_plan_id')->constrained('job_prep_plans')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->integer('crew_size')->default(2);
            $table->float('hours_per_day')->default(8);
            $table->date('scheduled_date')->nullable();
            $table->float('total_labor_hours')->default(0);
            $table->float('total_cleanup_hours')->default(0);
            $table->integer('total_days')->default(1);
            $table->timestamps();

            $table->unique(['job_prep_plan_id', 'service_category_id'], 'prep_service_settings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_prep_service_settings');
    }
};
