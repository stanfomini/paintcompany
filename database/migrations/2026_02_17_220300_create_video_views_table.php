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
        Schema::create('video_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_recording_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('viewer_email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->unsignedInteger('watch_duration_seconds')->default(0);
            $table->unsignedTinyInteger('watch_percentage')->default(0);
            $table->string('source')->default('link');
            $table->timestamps();

            $table->index('video_recording_id');
            $table->index('viewer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_views');
    }
};
