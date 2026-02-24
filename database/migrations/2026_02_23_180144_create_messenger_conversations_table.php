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
        Schema::create('messenger_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('messenger_bot_flows')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->string('facebook_psid');
            $table->foreignId('current_step_id')->nullable()->constrained('messenger_bot_steps')->nullOnDelete();
            $table->string('status')->default('in_progress');
            $table->json('answers')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index('facebook_psid');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messenger_conversations');
    }
};
