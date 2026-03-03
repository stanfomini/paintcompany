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
        Schema::table('video_projects', function (Blueprint $table) {
            $table->string('creation_mode')->default('classic')->after('status');
            $table->string('aspect_ratio')->default('16:9')->after('creation_mode');
            $table->text('ai_prompt')->nullable()->after('aspect_ratio');
            $table->foreignId('template_id')->nullable()->after('ai_prompt')
                ->constrained('studio_templates')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn(['creation_mode', 'aspect_ratio', 'ai_prompt', 'template_id']);
        });
    }
};
