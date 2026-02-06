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
        Schema::create('job_crew', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('painting_jobs')->cascadeOnDelete();
            $table->foreignId('crew_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('dispatched');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'crew_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_crew');
    }
};
