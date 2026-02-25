<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('max_duration_seconds')->default(120);
            $table->timestamp('expires_at');
            $table->foreignId('video_recording_id')->nullable()->constrained('video_recordings')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['token', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_invites');
    }
};
