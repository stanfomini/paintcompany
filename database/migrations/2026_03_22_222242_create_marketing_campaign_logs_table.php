<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaign_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_strategy_id')->constrained()->cascadeOnDelete();
            $table->string('trigger_type');
            $table->unsignedBigInteger('trigger_id')->nullable();
            $table->string('trigger_model')->nullable();
            $table->string('action');
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_address')->nullable();
            $table->text('message_body')->nullable();
            $table->string('status')->default('queued');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['marketing_strategy_id', 'created_at']);
            $table->index('trigger_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_logs');
    }
};
