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
        Schema::create('workflow_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_node_id')->constrained('workflow_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('workflow_nodes')->cascadeOnDelete();
            $table->string('branch')->nullable();
            $table->json('condition_config')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('workflow_id');
            $table->index('from_node_id');
            $table->index('to_node_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_edges');
    }
};
