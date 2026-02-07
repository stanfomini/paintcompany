<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_prep_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->unique()->constrained('painting_jobs')->cascadeOnDelete();
            $table->integer('crew_size')->default(2);
            $table->float('hours_per_day')->default(8);
            $table->integer('total_days')->default(1);
            $table->float('total_labor_hours')->default(0);
            $table->float('total_cleanup_hours')->default(0);
            $table->boolean('team_approved')->default(false);
            $table->boolean('crew_approved')->default(false);
            $table->boolean('customer_approved')->default(false);
            $table->timestamp('team_approved_at')->nullable();
            $table->timestamp('crew_approved_at')->nullable();
            $table->timestamp('customer_approved_at')->nullable();
            $table->foreignId('team_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('crew_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_prep_plans');
    }
};
