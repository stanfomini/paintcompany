<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_stars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('starrable_type');
            $table->unsignedBigInteger('starrable_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'starrable_type', 'starrable_id']);
            $table->index(['starrable_type', 'starrable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_stars');
    }
};
