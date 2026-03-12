<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_section_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_video_section_id')
                ->constrained('landing_page_video_sections', indexName: 'lpsp_section_id_foreign')
                ->cascadeOnDelete();
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('disk')->nullable();
            $table->string('caption')->nullable();
            $table->string('original_filename')->nullable();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_photo_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });

        Schema::table('landing_page_video_sections', function (Blueprint $table) {
            $table->boolean('show_on_proposal')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_section_photos');

        Schema::table('landing_page_video_sections', function (Blueprint $table) {
            $table->dropColumn('show_on_proposal');
        });
    }
};
