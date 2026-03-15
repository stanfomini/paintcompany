<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->json('default_visualization_colors')->nullable()->after('visualizer_image_prompt');
            $table->boolean('sms_bot_enabled')->default(false)->after('default_visualization_colors');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['default_visualization_colors', 'sms_bot_enabled']);
        });
    }
};
