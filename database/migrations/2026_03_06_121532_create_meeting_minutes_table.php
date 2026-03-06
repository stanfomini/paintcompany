<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->date('meeting_date');
            $table->string('meeting_type', 30);
            $table->string('audio_path')->nullable();
            $table->float('audio_duration')->nullable();
            $table->longText('transcript')->nullable();
            $table->json('transcript_segments')->nullable();
            $table->longText('executive_summary')->nullable();
            $table->string('summary_status', 20)->default('pending');
            $table->json('attendees')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_minutes');
    }
};
