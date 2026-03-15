<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_preview_visualizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_preview_photo_id')->constrained('project_preview_photos')->cascadeOnDelete();
            $table->string('result_image_path');
            $table->string('disk')->default('public');
            $table->string('color_name');
            $table->string('color_hex', 7);
            $table->string('color_brand')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('pending');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_preview_visualizations');
    }
};
