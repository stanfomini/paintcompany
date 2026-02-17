<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_recordings', function (Blueprint $table) {
            $table->string('visibility')->default('unlisted')->after('share_token');
            $table->text('description')->nullable()->after('visibility');
            $table->json('metadata')->nullable()->after('description');

            if (DB::getDriverName() === 'mysql') {
                $table->index('visibility');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_recordings', function (Blueprint $table) {
            if (DB::getDriverName() === 'mysql') {
                $table->dropIndex(['visibility']);
            }

            $table->dropColumn(['visibility', 'description', 'metadata']);
        });
    }
};
