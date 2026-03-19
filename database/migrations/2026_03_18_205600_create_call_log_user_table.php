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
        Schema::create('call_log_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_log_id')->constrained('call_logs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('listened_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('handled_action')->nullable();
            $table->timestamps();

            $table->unique(['call_log_id', 'user_id']);
            $table->index(['user_id', 'listened_at']);
            $table->index(['user_id', 'handled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_log_user');
    }
};
