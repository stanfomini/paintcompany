<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_project_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_project_scene_id')->constrained('video_project_scenes')->cascadeOnDelete();
            $table->string('slot')->default('main');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('in_point_ms')->default(0);
            $table->unsignedInteger('out_point_ms')->nullable();
            $table->unsignedInteger('volume')->default(100);
            $table->string('fit_mode')->default('cover');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('video_project_scene_id');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_project_clips');
    }
};
