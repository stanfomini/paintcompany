<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->text('content');
            $table->string('type')->default('text'); // 'text', 'file', 'image', 'system'
            $table->json('metadata')->nullable();
            $table->boolean('is_internal')->default(false); // Staff-only messages
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['thread_id', 'created_at']);
            $table->index(['thread_id', 'is_internal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
