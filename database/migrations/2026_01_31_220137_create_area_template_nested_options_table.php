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
        Schema::create('area_template_nested_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_template_id')->constrained('estimate_templates')->cascadeOnDelete();
            $table->foreignId('nested_template_id')->nullable()->constrained('estimate_templates')->nullOnDelete();
            $table->foreignId('nested_area_template_id')->nullable()->constrained('nested_area_templates')->nullOnDelete();
            $table->string('name'); // Display name override
            $table->boolean('is_default_included')->default(false);
            $table->unsignedTinyInteger('max_quantity')->nullable(); // null = unlimited
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['parent_template_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('area_template_nested_options');
    }
};
