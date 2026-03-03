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
        Schema::create('studio_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category'); // before_after, walkthrough, testimonial, estimate_tour, team_highlight, promo
            $table->string('thumbnail_path')->nullable();
            $table->json('structure_json'); // skeleton: segments, auto-fill targets, overlay positions
            $table->text('system_prompt')->nullable(); // AI instructions for filling this template
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('studio_templates');
    }
};
