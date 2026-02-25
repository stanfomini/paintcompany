<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_recordings', function (Blueprint $table) {
            $table->boolean('available_in_hub')->default(false)->after('show_on_proposal');
        });
    }

    public function down(): void
    {
        Schema::table('video_recordings', function (Blueprint $table) {
            $table->dropColumn('available_in_hub');
        });
    }
};
