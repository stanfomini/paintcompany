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
        Schema::dropIfExists('estimate_photos');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('estimate_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('estimate_areas')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('filename');
            $table->string('caption')->nullable();
            $table->string('category')->default('other');
            $table->boolean('show_on_proposal')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['estimate_id', 'category']);
        });
    }
};
