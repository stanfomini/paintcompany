<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stream_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('room_id')->unique();
            $table->string('title')->nullable();
            $table->string('status')->default('preparing'); // preparing, live, ended
            $table->json('scenes')->nullable();
            $table->unsignedTinyInteger('current_scene_index')->default(0);
            $table->json('destinations')->nullable();
            $table->json('overlays')->nullable();
            $table->string('recording_path')->nullable();
            $table->string('recording_disk')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_sessions');
    }
};
