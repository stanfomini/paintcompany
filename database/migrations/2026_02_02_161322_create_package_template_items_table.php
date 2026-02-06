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
        Schema::create('package_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_template_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g., "Cleaning Service", "Color Consultation"
            $table->text('description')->nullable();
            $table->string('pricing_type')->default('fixed'); // fixed, percent
            $table->decimal('cost', 10, 2)->default(0); // Cost to the company
            $table->decimal('price', 10, 2)->default(0); // Price charged to customer (for fixed pricing)
            $table->decimal('percent', 5, 2)->nullable(); // % of job (for percent pricing)
            $table->boolean('is_optional')->default(true); // Can customer toggle this?
            $table->boolean('is_included_by_default')->default(true); // Pre-checked?
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_template_items');
    }
};
