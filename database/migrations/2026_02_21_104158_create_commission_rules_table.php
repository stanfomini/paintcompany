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
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('commissionable_type');
            $table->unsignedBigInteger('commissionable_id');
            $table->string('name');
            $table->string('source');
            $table->string('commission_type');
            $table->decimal('rate', 8, 4)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->json('conditions')->nullable();
            $table->boolean('auto_trigger')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['commissionable_type', 'commissionable_id', 'source'], 'commission_rules_poly_source_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
