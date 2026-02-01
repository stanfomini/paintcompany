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
        Schema::create('estimate_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('surface_id')->nullable()->constrained('estimate_area_surfaces')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('pricing_type'); // fixed, per_sqft, per_surface
            $table->decimal('fixed_price', 10, 2)->nullable();
            $table->decimal('multiplier', 5, 3)->default(1.000);
            $table->decimal('calculated_price', 10, 2)->default(0);
            $table->boolean('is_selected')->default(false);
            $table->boolean('is_recommended')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('estimate_id');
            $table->index('surface_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_options');
    }
};
