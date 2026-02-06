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
        Schema::create('package_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Good", "Better", "Best"
            $table->string('tier')->unique(); // good, better, best - used as identifier
            $table->text('description')->nullable();
            $table->unsignedInteger('warranty_months')->default(0); // e.g., 3, 6, 12
            $table->decimal('warranty_percent', 5, 2)->default(0); // % of job for warranty cost
            $table->boolean('is_default')->default(false); // Pre-selected package
            $table->boolean('is_popular')->default(false); // Show "Popular" badge
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_templates');
    }
};
