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
        Schema::create('live_event_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('instructions')->nullable();
            $table->string('token', 64)->unique();
            $table->string('status')->default('invited');
            $table->string('livekit_identity')->nullable();
            $table->unsignedTinyInteger('slot_number')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('went_live_at')->nullable();
            $table->timestamps();

            $table->index(['live_event_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_event_guests');
    }
};
