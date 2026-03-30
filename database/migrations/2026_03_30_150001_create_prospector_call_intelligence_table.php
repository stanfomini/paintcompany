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
        Schema::create('prospector_call_intelligence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->unique()->constrained('prospector_calls')->cascadeOnDelete();
            $table->longText('transcription_raw')->nullable();
            $table->json('transcription_segments')->nullable();
            $table->json('speaker_labels')->nullable();
            $table->decimal('sentiment_overall', 3, 2)->nullable();
            $table->json('sentiment_by_segment')->nullable();
            $table->unsignedTinyInteger('lead_score')->nullable();
            $table->json('scoring_factors')->nullable();
            $table->json('keywords')->nullable();
            $table->json('objections')->nullable();
            $table->json('action_items')->nullable();
            $table->text('ai_summary')->nullable();
            $table->text('ai_coaching_notes')->nullable();
            $table->string('model_version')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('lead_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_call_intelligence');
    }
};
