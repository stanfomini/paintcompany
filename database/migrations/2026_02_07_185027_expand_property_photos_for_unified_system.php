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
        Schema::table('property_photos', function (Blueprint $table) {
            // Storage variant paths
            $table->string('disk', 20)->default('public')->after('path');
            $table->string('original_path', 500)->nullable()->after('disk');
            $table->string('thumbnail_path', 500)->nullable()->after('original_path');
            $table->string('medium_path', 500)->nullable()->after('thumbnail_path');
            $table->string('annotated_path', 500)->nullable()->after('medium_path');

            // File metadata
            $table->string('original_filename', 255)->nullable()->after('annotated_path');
            $table->string('mime_type', 50)->nullable()->after('original_filename');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            $table->unsignedInteger('width')->nullable()->after('file_size');
            $table->unsignedInteger('height')->nullable()->after('width');

            // Estimate/job associations
            $table->boolean('show_on_proposal')->default(false)->after('category');
            $table->foreignId('estimate_id')->nullable()->after('show_on_proposal')->constrained()->nullOnDelete();
            $table->foreignId('estimate_area_id')->nullable()->after('estimate_id')->constrained('estimate_areas')->nullOnDelete();
            $table->foreignId('job_id')->nullable()->after('estimate_area_id')->constrained('painting_jobs')->nullOnDelete();

            // Processing and EXIF
            $table->string('processing_status', 20)->default('complete')->after('job_id');
            $table->timestamp('taken_at')->nullable()->after('processing_status');
            $table->decimal('latitude', 10, 7)->nullable()->after('taken_at');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            // Indexes
            $table->index('estimate_id');
            $table->index('job_id');
            $table->index('show_on_proposal');
            $table->index('processing_status');
        });

        // Change category from enum to string to support more values
        if (config('database.default') !== 'sqlite') {
            Schema::table('property_photos', function (Blueprint $table) {
                $table->string('category', 20)->default('general')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_photos', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
            $table->dropForeign(['estimate_area_id']);
            $table->dropForeign(['job_id']);

            $table->dropIndex(['estimate_id']);
            $table->dropIndex(['job_id']);
            $table->dropIndex(['show_on_proposal']);
            $table->dropIndex(['processing_status']);

            $table->dropColumn([
                'disk', 'original_path', 'thumbnail_path', 'medium_path', 'annotated_path',
                'original_filename', 'mime_type', 'file_size', 'width', 'height',
                'show_on_proposal', 'estimate_id', 'estimate_area_id', 'job_id',
                'processing_status', 'taken_at', 'latitude', 'longitude',
            ]);
        });
    }
};
