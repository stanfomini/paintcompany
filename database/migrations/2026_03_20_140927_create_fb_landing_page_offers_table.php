<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fb_landing_page_offers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('headline');
            $table->text('subheadline')->nullable();
            $table->json('offer_bullets')->nullable();
            $table->string('cta_text')->default('Claim Your Free Estimate');
            $table->foreignId('video_recording_id')->nullable()->constrained('video_recordings')->nullOnDelete();
            $table->string('hero_photo_path')->nullable();
            $table->string('hero_photo_disk')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('fb_pixel_id')->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fb_landing_page_offers');
    }
};
