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
        Schema::create('production_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('production_rate_categories')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit_type')->default('sqft'); // sqft, lnft, unit, hour
            $table->decimal('production_rate', 10, 2)->default(0); // sqft per hour
            $table->decimal('hourly_labor_rate', 10, 2)->default(0);
            $table->decimal('material_cost_per_unit', 10, 2)->default(0);
            $table->json('coat_multipliers')->nullable(); // {"1": 1.0, "2": 1.8, "3": 2.5}
            $table->integer('default_coats')->default(2);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'sort_order']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_rates');
    }
};
