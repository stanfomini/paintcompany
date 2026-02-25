<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_project_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_project_id')->constrained('video_projects')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('name')->nullable();
            $table->string('layout')->default('solo');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('trim_start_ms')->default(0);
            $table->unsignedInteger('trim_end_ms')->nullable();
            $table->string('transition_type')->default('cut');
            $table->unsignedInteger('transition_duration_ms')->default(0);
            $table->string('background_color')->default('#000000');
            $table->timestamps();

            $table->index(['video_project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_project_scenes');
    }
};
