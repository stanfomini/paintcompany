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
        Schema::create('nested_template_surfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nested_area_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('surface_type');
            $table->string('calculation_method')->default('ratio');
            $table->decimal('calculation_value', 10, 4)->default(1.0);
            $table->string('unit_type')->default('sqft');
            $table->foreignId('production_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('default_coats')->default(2);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_default_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nested_template_surfaces');
    }
};
