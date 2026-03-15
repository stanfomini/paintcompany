<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_preview_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained('sms_bot_flows')->nullOnDelete();
            $table->string('phone');
            $table->string('current_state');
            $table->foreignId('sms_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chat_thread_id')->nullable()->constrained('chat_threads')->nullOnDelete();
            $table->json('answers')->nullable();
            $table->integer('message_count')->default(0);
            $table->integer('photo_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index('phone');
            $table->index('current_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_conversations');
    }
};
