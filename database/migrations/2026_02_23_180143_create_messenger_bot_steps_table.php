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
        Schema::create('messenger_bot_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('messenger_bot_flows')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('step_type');
            $table->text('message_text');
            $table->json('quick_reply_options')->nullable();
            $table->string('field_mapping', 100)->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messenger_bot_steps');
    }
};
