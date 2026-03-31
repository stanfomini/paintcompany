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
        Schema::create('prospector_agent_company', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('prospector_agents')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('prospector_companies')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['agent_id', 'company_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_agent_company');
    }
};
