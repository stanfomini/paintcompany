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
        Schema::create('video_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_recording_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('subject');
            $table->text('message')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_emails');
    }
};
