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
        Schema::create('prospector_dialer_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('agent_id')->constrained('prospector_agents')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('prospector_campaigns')->cascadeOnDelete();
            $table->string('mode')->default('single');
            $table->string('status')->default('active');
            $table->unsignedInteger('calls_attempted')->default(0);
            $table->unsignedInteger('calls_connected')->default(0);
            $table->unsignedInteger('total_talk_seconds')->default(0);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_dialer_sessions');
    }
};
