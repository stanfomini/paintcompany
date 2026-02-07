<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_prep_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_prep_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_prep_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estimate_area_id')->nullable()->constrained('estimate_areas')->nullOnDelete();
            $table->foreignId('estimate_area_surface_id')->nullable()->constrained('estimate_area_surfaces')->nullOnDelete();
            $table->foreignId('estimate_area_addon_id')->nullable()->constrained('estimate_area_addons')->nullOnDelete();
            $table->string('task_type'); // surface, addon, custom
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('area_name')->nullable();
            $table->string('surface_name')->nullable();
            $table->float('quantity')->nullable();
            $table->string('unit')->nullable();
            $table->integer('coats')->nullable();
            $table->float('estimated_hours')->nullable();
            $table->string('status')->default('pending'); // pending, in_progress, completed, skipped
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['job_prep_day_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_prep_tasks');
    }
};
