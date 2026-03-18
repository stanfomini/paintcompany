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
        Schema::create('template_cost_items', function (Blueprint $table) {
            $table->id();
            $table->morphs('costable');
            $table->string('name');
            $table->string('cost_type');
            $table->decimal('default_quantity', 10, 2)->nullable();
            $table->string('quantity_calculation')->default('fixed');
            $table->decimal('quantity_value', 10, 4)->default(1);
            $table->string('unit')->default('each');
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->string('tier')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_cost_items');
    }
};
