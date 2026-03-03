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
        Schema::table('video_project_clips', function (Blueprint $table) {
            $table->string('transition_type')->nullable()->after('fit_mode');
            $table->unsignedInteger('transition_duration_ms')->default(500)->after('transition_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_project_clips', function (Blueprint $table) {
            $table->dropColumn(['transition_type', 'transition_duration_ms']);
        });
    }
};
