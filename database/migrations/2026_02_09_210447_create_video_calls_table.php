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
        Schema::create('video_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('painting_jobs')->cascadeOnDelete();
            $table->foreignId('initiated_by')->constrained('users');
            $table->string('room_id')->unique();
            $table->string('status')->default('waiting'); // waiting, active, ended
            $table->string('recording_path')->nullable();
            $table->string('recording_disk')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->json('participants')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_calls');
    }
};
