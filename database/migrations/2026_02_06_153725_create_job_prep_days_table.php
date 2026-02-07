<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_prep_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_prep_plan_id')->constrained()->cascadeOnDelete();
            $table->integer('day_number');
            $table->string('label')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['job_prep_plan_id', 'day_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_prep_days');
    }
};
