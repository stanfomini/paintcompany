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
        Schema::create('paint_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paint_brand_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Duration, Emerald, Aura, etc.
            $table->string('code')->unique(); // sw_duration, bm_aura, etc.
            $table->text('description')->nullable();

            // Product classification
            $table->string('sheen')->default('eggshell'); // flat, matte, eggshell, satin, semi-gloss, gloss
            $table->string('product_type')->default('paint'); // paint, primer, stain, sealer
            $table->string('scope_type')->default('interior'); // interior, exterior, both
            $table->string('surface_type')->default('all'); // all, walls, trim, ceiling, doors, cabinets

            // Pricing
            $table->decimal('price_per_gallon', 10, 2);
            $table->decimal('price_per_five_gallon', 10, 2)->nullable(); // Often discounted

            // Coverage rates (sqft per gallon)
            $table->integer('coverage_sqft_brush_roll')->default(350); // Standard brush/roll coverage
            $table->integer('coverage_sqft_spray')->default(250); // Spray coverage (uses more paint)

            // Special attributes
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_low_voc')->default(false);
            $table->boolean('is_self_priming')->default(false);
            $table->boolean('requires_tinting')->default(true);

            // Coat recommendations
            $table->integer('recommended_coats')->default(2);
            $table->decimal('second_coat_coverage_multiplier', 4, 2)->default(1.20); // Usually get more coverage on 2nd coat

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['paint_brand_id', 'is_active']);
            $table->index('scope_type');
            $table->index('product_type');
            $table->index('sheen');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paint_products');
    }
};
