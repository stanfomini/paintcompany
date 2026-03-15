<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_preview_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_preview_id')->constrained()->cascadeOnDelete();
            $table->string('original_path');
            $table->string('disk')->default('public');
            $table->string('room_label')->nullable();
            $table->json('substrate_analysis')->nullable();
            $table->string('condition_notes')->nullable();
            $table->string('mime_type');
            $table->integer('file_size');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_preview_photos');
    }
};
