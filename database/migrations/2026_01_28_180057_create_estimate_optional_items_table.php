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
        Schema::create('estimate_optional_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('estimate_templates')->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('pricing_type', ['fixed', 'percent'])->default('fixed');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('percent_of_total', 5, 2)->nullable();

            // Customer selection
            $table->boolean('is_selected_by_customer')->default(false);
            $table->timestamp('selected_at')->nullable();

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['estimate_id', 'is_selected_by_customer'], 'est_opt_items_estimate_selected_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_optional_items');
    }
};
