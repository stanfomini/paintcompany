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
        Schema::create('training_path_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_path_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_recording_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_path_steps');
    }
};
