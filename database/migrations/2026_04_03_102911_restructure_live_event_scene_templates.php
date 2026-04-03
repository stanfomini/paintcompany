<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existingTemplates = DB::table('live_event_scene_templates')->get();

        $newRows = [];
        foreach ($existingTemplates as $template) {
            $scenes = json_decode($template->scenes, true) ?? [];
            $globalOverlays = json_decode($template->overlays, true) ?? [];

            if (empty($scenes)) {
                continue;
            }

            foreach ($scenes as $scene) {
                if (empty($scene['overlays'] ?? []) && ! empty($globalOverlays)) {
                    $scene['overlays'] = $globalOverlays;
                }

                $newRows[] = [
                    'user_id' => $template->user_id,
                    'name' => $template->name.' — '.($scene['name'] ?? 'Scene'),
                    'scenes' => json_encode($scene),
                    'overlays' => null,
                    'created_at' => $template->created_at,
                    'updated_at' => $template->updated_at,
                ];
            }
        }

        DB::table('live_event_scene_templates')->truncate();

        foreach ($newRows as $row) {
            DB::table('live_event_scene_templates')->insert($row);
        }

        Schema::table('live_event_scene_templates', function (Blueprint $table) {
            $table->renameColumn('scenes', 'scene_data');
        });

        Schema::table('live_event_scene_templates', function (Blueprint $table) {
            $table->string('base_layout')->nullable()->after('name');
            $table->dropColumn('overlays');
        });
    }

    public function down(): void
    {
        Schema::table('live_event_scene_templates', function (Blueprint $table) {
            $table->renameColumn('scene_data', 'scenes');
        });

        Schema::table('live_event_scene_templates', function (Blueprint $table) {
            $table->json('overlays')->nullable()->after('scenes');
            $table->dropColumn('base_layout');
        });
    }
};
