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
        Schema::create('estimate_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('tier', ['basic', 'standard', 'premium', 'custom'])->default('standard');
            $table->text('description')->nullable();
            $table->text('includes')->nullable();
            $table->text('excludes')->nullable();

            // Pricing
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Warranty
            $table->decimal('warranty_price', 12, 2)->default(0);
            $table->integer('warranty_years')->nullable();
            $table->string('warranty_type')->nullable();

            // Package state
            $table->boolean('is_recommended')->default(false);
            $table->boolean('is_selected')->default(false);
            $table->integer('sort_order')->default(0);

            // Signature when customer signs this package
            $table->foreignId('signature_id')->nullable()->constrained('estimate_signatures')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();

            $table->timestamps();

            $table->index(['estimate_id', 'is_selected']);
            $table->index(['estimate_id', 'tier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_packages');
    }
};
