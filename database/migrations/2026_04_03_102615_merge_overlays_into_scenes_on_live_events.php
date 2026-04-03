<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Merge global overlays into each scene's overlays array
        DB::table('live_events')
            ->whereNotNull('overlays')
            ->where('overlays', '!=', '[]')
            ->where('overlays', '!=', 'null')
            ->orderBy('id')
            ->each(function ($event) {
                $globalOverlays = json_decode($event->overlays, true) ?? [];
                $scenes = json_decode($event->scenes, true) ?? [];

                if (empty($globalOverlays) || empty($scenes)) {
                    return;
                }

                foreach ($scenes as &$scene) {
                    $existingOverlays = $scene['overlays'] ?? [];
                    // Merge global overlays that aren't already in the scene
                    $scene['overlays'] = array_merge($existingOverlays, $globalOverlays);
                }

                DB::table('live_events')
                    ->where('id', $event->id)
                    ->update(['scenes' => json_encode($scenes)]);
            });

        Schema::table('live_events', function (Blueprint $table) {
            $table->dropColumn('overlays');
        });
    }

    public function down(): void
    {
        Schema::table('live_events', function (Blueprint $table) {
            $table->json('overlays')->nullable()->after('active_scene_index');
        });
    }
};
