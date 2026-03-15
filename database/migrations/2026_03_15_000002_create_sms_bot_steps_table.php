<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_bot_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('sms_bot_flows')->cascadeOnDelete();
            $table->integer('sort_order');
            $table->string('step_type');
            $table->text('message_text');
            $table->json('quick_reply_options')->nullable();
            $table->string('field_mapping')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('delay_seconds')->nullable();
            $table->foreignId('next_step_id')->nullable()->constrained('sms_bot_steps')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_bot_steps');
    }
};
