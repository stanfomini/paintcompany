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
        Schema::create('prospector_calls', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained('prospector_companies')->cascadeOnDelete();
            $table->foreignId('dialer_session_id')->nullable()->constrained('prospector_dialer_sessions')->nullOnDelete();
            $table->foreignId('agent_id')->constrained('prospector_agents')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('prospector_contacts')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('prospector_campaigns')->nullOnDelete();
            $table->string('direction')->default('outbound');
            $table->string('from_number', 20);
            $table->unsignedBigInteger('from_phone_number_id')->nullable();
            $table->foreign('from_phone_number_id')->references('id')->on('prospector_phone_numbers')->nullOnDelete();
            $table->string('to_number', 20);
            $table->string('twilio_call_sid')->nullable();
            $table->string('twilio_conference_sid')->nullable();
            $table->string('status')->default('queued');
            $table->string('disposition')->nullable();
            $table->text('disposition_notes')->nullable();
            $table->string('amd_result')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('talk_seconds')->nullable();
            $table->string('recording_url')->nullable();
            $table->string('recording_path')->nullable();
            $table->timestamp('callback_at')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'agent_id', 'started_at']);
            $table->index(['twilio_call_sid']);
            $table->index('dialer_session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_calls');
    }
};
