<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_bot_flows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('trigger_type')->default('new_lead');
            $table->json('lead_source_ids')->nullable();
            $table->text('greeting_message');
            $table->text('photo_prompt_message');
            $table->text('visualization_message');
            $table->text('portal_link_message');
            $table->integer('timeout_minutes')->default(10080);
            $table->boolean('business_hours_only')->default(false);
            $table->integer('delay_after_lead_minutes')->default(5);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_bot_flows');
    }
};
