<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_area_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_area_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('custom_h1')->nullable();
            $table->text('custom_intro')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['service_area_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_area_service');
    }
};
