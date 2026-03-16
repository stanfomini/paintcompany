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
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('twilio_call_sid')->unique()->index();
            $table->string('direction'); // inbound, outbound
            $table->string('from_number');
            $table->string('to_number');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('caller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('ringing'); // ringing, in-progress, completed, no-answer, busy, failed, voicemail
            $table->integer('duration_seconds')->nullable();
            $table->string('recording_sid')->nullable();
            $table->string('recording_url')->nullable();
            $table->integer('recording_duration_seconds')->nullable();
            $table->text('voicemail_transcription')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
