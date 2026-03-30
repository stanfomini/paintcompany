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
        Schema::create('prospector_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('prospector_companies')->cascadeOnDelete();
            $table->string('status')->default('offline');
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedTinyInteger('max_concurrent_lines')->default(1);
            $table->unsignedInteger('wrap_up_seconds')->default(30);
            $table->unsignedInteger('daily_call_count')->default(0);
            $table->unsignedInteger('daily_talk_minutes')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'company_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_agents');
    }
};
