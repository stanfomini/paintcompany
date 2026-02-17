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
        Schema::create('video_channel_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_recording_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['video_channel_id', 'video_recording_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_channel_items');
    }
};
