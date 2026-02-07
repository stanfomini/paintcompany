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
        Schema::create('photo_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_photo_id')->constrained('property_photos')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('canvas_json');
            $table->string('canvas_version')->default('6.0');
            $table->string('rendered_path', 500)->nullable();
            $table->timestamps();

            $table->index('property_photo_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_annotations');
    }
};
