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
        Schema::create('live_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');
            $table->foreignId('service_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('status')->default('draft');
            $table->string('access_type')->default('public');
            $table->integer('max_viewers')->default(50);
            $table->boolean('enable_recording')->default(false);
            $table->string('livekit_room_name')->unique();
            $table->string('recording_path')->nullable();
            $table->string('cover_image')->nullable();
            $table->foreignId('chat_thread_id')->nullable()->constrained('chat_threads')->nullOnDelete();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
            $table->index('service_area_id');
            $table->index('service_id');
        });

        Schema::create('live_event_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_event_id')->constrained('live_events')->cascadeOnDelete();
            $table->string('title');
            $table->string('url', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('type')->default('link');
            $table->boolean('is_pinned')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('live_event_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_event_id')->constrained('live_events')->cascadeOnDelete();
            $table->string('viewer_name');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('question');
            $table->string('status')->default('pending');
            $table->dateTime('answered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('live_event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_event_id')->constrained('live_events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->boolean('attended')->default(false);
            $table->timestamps();

            $table->unique(['live_event_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_event_registrations');
        Schema::dropIfExists('live_event_questions');
        Schema::dropIfExists('live_event_resources');
        Schema::dropIfExists('live_events');
    }
};
