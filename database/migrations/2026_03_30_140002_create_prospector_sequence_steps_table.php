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
        Schema::create('prospector_sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('prospector_sequences')->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->string('type');
            $table->unsignedInteger('delay_value')->nullable();
            $table->string('delay_unit')->nullable();
            $table->json('config');
            $table->timestamps();

            $table->index(['sequence_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_sequence_steps');
    }
};
