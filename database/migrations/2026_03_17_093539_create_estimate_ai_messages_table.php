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
        Schema::create('estimate_ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('estimate_ai_threads')->cascadeOnDelete();
            $table->string('role'); // user, assistant, error
            $table->text('content');
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_ai_messages');
    }
};
