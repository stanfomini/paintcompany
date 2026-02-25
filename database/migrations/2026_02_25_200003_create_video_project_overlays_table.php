<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_project_overlays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_project_scene_id')->constrained('video_project_scenes')->cascadeOnDelete();
            $table->string('type');
            $table->text('content');
            $table->json('style')->nullable();
            $table->decimal('position_x', 5, 4)->default(0);
            $table->decimal('position_y', 5, 4)->default(0);
            $table->decimal('width', 5, 4)->nullable();
            $table->decimal('height', 5, 4)->nullable();
            $table->unsignedInteger('start_ms')->default(0);
            $table->unsignedInteger('end_ms')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('video_project_scene_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_project_overlays');
    }
};
