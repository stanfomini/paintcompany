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
        Schema::create('estimate_area_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_area_id')->constrained('estimate_areas')->cascadeOnDelete();
            $table->foreignId('nested_area_template_id')->constrained('nested_area_templates')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->timestamps();

            // Index for common queries
            $table->index('estimate_area_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_area_addons');
    }
};
