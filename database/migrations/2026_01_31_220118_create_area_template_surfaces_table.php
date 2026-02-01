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
        Schema::create('area_template_surfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_template_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // "Walls", "Ceiling", "Baseboards"
            $table->enum('surface_type', ['walls', 'ceiling', 'trim', 'doors', 'windows', 'floor', 'other'])->default('other');
            $table->enum('calculation_method', ['ratio', 'per_100sqft', 'per_lnft', 'fixed_count', 'fixed_price'])->default('ratio');
            $table->decimal('calculation_value', 10, 4)->default(1.0); // 1.0 for ratio, 28 for per_100sqft, etc.
            $table->enum('unit_type', ['sqft', 'lnft', 'units'])->default('sqft');
            $table->foreignId('production_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('default_coats')->default(2);
            $table->boolean('is_required')->default(false); // Always included
            $table->boolean('is_default_enabled')->default(true); // Checked by default
            $table->decimal('fixed_price', 10, 2)->nullable(); // For fixed_price method
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['estimate_template_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('area_template_surfaces');
    }
};
