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
        Schema::table('live_events', function (Blueprint $table) {
            $table->json('scenes')->nullable()->after('cover_image');
            $table->unsignedInteger('active_scene_index')->default(0)->after('scenes');
            $table->json('overlays')->nullable()->after('active_scene_index');
            $table->boolean('use_frame_mode')->default(true)->after('overlays');
            $table->foreignId('backstage_chat_thread_id')->nullable()->constrained('chat_threads')->nullOnDelete()->after('chat_thread_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('live_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backstage_chat_thread_id');
            $table->dropColumn(['scenes', 'active_scene_index', 'overlays', 'use_frame_mode']);
        });
    }
};
