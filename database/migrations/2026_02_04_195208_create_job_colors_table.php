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
        Schema::create('job_colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('painting_jobs')->cascadeOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('code')->nullable();
            $table->string('hex_value')->nullable();
            $table->string('surface_area')->nullable();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_colors');
    }
};
