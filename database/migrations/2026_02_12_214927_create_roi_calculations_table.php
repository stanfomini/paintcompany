<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roi_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('property_value', 12, 2);
            $table->unsignedInteger('estimated_sqft');
            $table->unsignedTinyInteger('bedroom_count');
            $table->string('scope');
            $table->string('intent');
            $table->decimal('estimated_cost_low', 10, 2);
            $table->decimal('estimated_cost_high', 10, 2);
            $table->decimal('estimated_value_increase_low', 10, 2);
            $table->decimal('estimated_value_increase_high', 10, 2);
            $table->decimal('roi_percent_low', 5, 2);
            $table->decimal('roi_percent_high', 5, 2);
            $table->json('calculation_inputs')->nullable();
            $table->string('public_token', 64)->unique()->nullable();
            $table->datetime('public_token_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roi_calculations');
    }
};
