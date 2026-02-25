<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedInteger('resolution_width')->default(1920);
            $table->unsignedInteger('resolution_height')->default(1080);
            $table->unsignedInteger('fps')->default(30);
            $table->decimal('duration_seconds', 10, 2)->nullable();
            $table->foreignId('video_recording_id')->nullable()->constrained('video_recordings')->nullOnDelete();
            $table->string('share_token', 32)->unique()->nullable();
            $table->string('visibility')->default('internal');
            $table->string('thumbnail_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_projects');
    }
};
