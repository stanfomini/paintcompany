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
        Schema::create('photo_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_photo_id')->constrained('property_photos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');

            $table->unique(['property_photo_id', 'user_id']);
            $table->index('user_id');
        });

        // Track when photo content last changed (upload, annotation, edit)
        Schema::table('property_photos', function (Blueprint $table) {
            $table->timestamp('content_updated_at')->nullable()->after('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_photos', function (Blueprint $table) {
            $table->dropColumn('content_updated_at');
        });

        Schema::dropIfExists('photo_views');
    }
};
