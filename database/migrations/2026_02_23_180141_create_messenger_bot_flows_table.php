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
        Schema::create('messenger_bot_flows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->text('greeting_message')->nullable();
            $table->string('completion_action', 50)->default('create_lead');
            $table->unsignedInteger('timeout_minutes')->default(30);
            $table->boolean('business_hours_only')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messenger_bot_flows');
    }
};
