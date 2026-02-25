<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remotion_build_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->text('system_context')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('video_recording_id')->nullable()->constrained('video_recordings')->nullOnDelete();
            $table->string('composition_id')->nullable();
            $table->json('props_json')->nullable();
            $table->string('log_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remotion_build_requests');
    }
};
