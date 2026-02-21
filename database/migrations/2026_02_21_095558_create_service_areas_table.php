<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_areas', function (Blueprint $table) {
            $table->id();
            $table->string('city');
            $table->string('state', 2)->default('CO');
            $table->string('slug')->unique();
            $table->json('zip_codes')->nullable();
            $table->foreignId('territory_id')->nullable()->constrained('sales_territories')->nullOnDelete();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('hero_text')->nullable();
            $table->text('specialties_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('slug');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_areas');
    }
};
