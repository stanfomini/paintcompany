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
        Schema::create('landing_page_showcase_photos', function (Blueprint $table) {
            $table->id();
            $table->string('before_photo_path');
            $table->string('after_photo_path');
            $table->string('before_thumbnail_path')->nullable();
            $table->string('after_thumbnail_path')->nullable();
            $table->string('disk')->nullable();
            $table->string('caption')->nullable();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_page_showcase_photos');
    }
};
