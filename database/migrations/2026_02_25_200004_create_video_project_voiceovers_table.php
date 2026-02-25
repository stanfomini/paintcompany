<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_project_voiceovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_project_id')->constrained('video_projects')->cascadeOnDelete();
            $table->foreignId('video_recording_id')->constrained('video_recordings')->cascadeOnDelete();
            $table->unsignedInteger('start_ms')->default(0);
            $table->unsignedInteger('volume')->default(100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('video_project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_project_voiceovers');
    }
};
