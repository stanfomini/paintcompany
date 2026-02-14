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
        Schema::create('ai_build_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, building, completed, nothing_to_build, unreadable_blueprints, failed, partial
            $table->unsignedInteger('areas_created')->default(0);
            $table->unsignedInteger('surfaces_created')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('log_path')->nullable();
            $table->text('error_message')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('callback_sent_at')->nullable();
            $table->timestamps();

            $table->index(['estimate_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_build_requests');
    }
};
