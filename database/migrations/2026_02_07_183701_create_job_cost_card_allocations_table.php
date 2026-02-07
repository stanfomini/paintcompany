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
        Schema::create('job_cost_card_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_cost_card_id')->constrained('job_cost_cards')->cascadeOnDelete();
            $table->foreignId('company_allocation_id')->nullable()->constrained('company_allocations')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type', 30);
            $table->decimal('value', 10, 4);
            $table->decimal('amount', 10, 2)->default(0);
            $table->json('conditions_snapshot')->nullable();
            $table->timestamps();

            $table->index(['job_cost_card_id']);
            $table->index(['company_allocation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_cost_card_allocations');
    }
};
