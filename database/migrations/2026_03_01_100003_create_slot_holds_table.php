<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_holds', function (Blueprint $table) {
            $table->id();
            $table->string('session_id');
            $table->foreignId('estimator_id')->constrained('users')->cascadeOnDelete();
            $table->date('hold_date');
            $table->time('hold_start_time');
            $table->time('hold_end_time');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['estimator_id', 'hold_date', 'expires_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_holds');
    }
};
