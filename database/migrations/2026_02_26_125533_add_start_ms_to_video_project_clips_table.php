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
            $table->unsignedBigInteger('start_ms')->default(0)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('video_project_clips', function (Blueprint $table) {
            $table->dropColumn('start_ms');
        });
    }
};
