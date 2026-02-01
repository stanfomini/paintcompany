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
        Schema::create('estimate_template_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('estimate_templates')->cascadeOnDelete();

            // Template configuration at time of use
            $table->decimal('quantity', 10, 2)->default(1);
            $table->integer('coats')->default(2);
            $table->decimal('calculated_price', 12, 2)->default(0);

            // Polymorphic result - what was created from this template
            $table->string('resultable_type')->nullable();
            $table->unsignedBigInteger('resultable_id')->nullable();

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['estimate_id', 'template_id']);
            $table->index(['resultable_type', 'resultable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_template_usages');
    }
};
