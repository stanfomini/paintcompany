<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_video_sections', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->boolean('is_active')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });

        Schema::create('landing_page_video_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_video_section_id')
                ->constrained('landing_page_video_sections', indexName: 'lpvsi_section_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('video_recording_id')
                ->constrained(indexName: 'lpvsi_video_id_foreign')
                ->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['landing_page_video_section_id', 'video_recording_id'],
                'lpvsi_section_video_unique'
            );
            $table->index('sort_order');
        });

        // Seed default sections
        $now = now();
        DB::table('landing_page_video_sections')->insert([
            [
                'slug' => 'tutorials',
                'title' => 'Tutorials',
                'subtitle' => null,
                'is_active' => false,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'testimonials',
                'title' => 'Testimonials',
                'subtitle' => null,
                'is_active' => false,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'showcases',
                'title' => 'Project Showcases',
                'subtitle' => null,
                'is_active' => false,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_video_section_items');
        Schema::dropIfExists('landing_page_video_sections');
    }
};
