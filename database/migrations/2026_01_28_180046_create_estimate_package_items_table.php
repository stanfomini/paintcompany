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
        Schema::create('estimate_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('estimate_packages')->cascadeOnDelete();

            // Polymorphic relationship to EstimateItem, EstimateArea, or EstimateAreaSurface
            $table->string('itemable_type');
            $table->unsignedBigInteger('itemable_id');

            // Package-specific overrides
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('price_override', 12, 2)->nullable();
            $table->boolean('is_included')->default(true);

            // Customer approval
            $table->boolean('customer_approved')->default(true);
            $table->timestamp('customer_approved_at')->nullable();

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['package_id', 'is_included']);
            $table->index(['itemable_type', 'itemable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_package_items');
    }
};
