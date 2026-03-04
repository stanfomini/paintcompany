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
        Schema::create('paint_colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paint_brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('hex_value', 7);
            $table->unsignedTinyInteger('rgb_r');
            $table->unsignedTinyInteger('rgb_g');
            $table->unsignedTinyInteger('rgb_b');
            $table->string('color_family')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['paint_brand_id', 'code']);
            $table->index('color_family');
            $table->index('is_popular');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paint_colors');
    }
};
