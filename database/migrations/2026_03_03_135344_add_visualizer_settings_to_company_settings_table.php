<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->text('visualizer_agent_prompt')->nullable();
            $table->text('visualizer_image_prompt')->nullable();
            $table->string('visualizer_image_model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'visualizer_agent_prompt',
                'visualizer_image_prompt',
                'visualizer_image_model',
            ]);
        });
    }
};
