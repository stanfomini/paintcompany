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
        Schema::create('workflow_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('trigger_model_type');
            $table->unsignedBigInteger('trigger_model_id');
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->json('trigger_snapshot')->nullable();
            $table->json('actions_executed')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('is_manual')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['workflow_id', 'created_at']);
            $table->index(['trigger_model_type', 'trigger_model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_logs');
    }
};
