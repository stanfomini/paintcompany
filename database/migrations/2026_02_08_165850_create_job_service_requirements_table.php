<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_service_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('painting_jobs')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->date('scheduled_date')->nullable();
            $table->integer('required_crew_size')->default(1);
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['job_id', 'service_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_service_requirements');
    }
};
