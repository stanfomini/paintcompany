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
        Schema::table('video_project_voiceovers', function (Blueprint $table) {
            $table->unsignedInteger('in_point_ms')->default(0)->after('start_ms');
            $table->unsignedInteger('out_point_ms')->nullable()->after('in_point_ms');
        });
    }

    public function down(): void
    {
        Schema::table('video_project_voiceovers', function (Blueprint $table) {
            $table->dropColumn(['in_point_ms', 'out_point_ms']);
        });
    }
};
