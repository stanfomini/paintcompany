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
        Schema::create('territory_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('sales_territories')->cascadeOnDelete();
            $table->string('period_type'); // monthly, quarterly, yearly
            $table->date('period_start');
            $table->decimal('marketing_budget', 12, 2)->default(0);
            $table->decimal('commission_budget_pct', 5, 2)->default(12.00);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['territory_id', 'period_type', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('territory_budgets');
    }
};
