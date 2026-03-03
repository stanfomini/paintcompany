<?php

namespace App\AI\Tools;

use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Tool as PrismTool;

class AddClipToProject
{
    public function __construct(private User $user) {}

    public static function definition(User $user): PrismTool
    {
        $instance = new self($user);

        return Tool::as('add_clip_to_project')
            ->for('Add a photo or video clip to a video project scene')
            ->withNumberParameter('project_id', 'The video project ID')
            ->withStringParameter('source_type', 'Source type: property-photo or video-recording')
            ->withNumberParameter('source_id', 'The ID of the source photo or video')
            ->withNumberParameter('duration_ms', 'Duration in milliseconds (default 3000 for photos)', required: false)
            ->using(fn (int $project_id, string $source_type, int $source_id, int $duration_ms = 3000): string => $instance->handle($project_id, $source_type, $source_id, $duration_ms));
    }

    public function handle(int $projectId, string $sourceType, int $sourceId, int $durationMs = 3000): string
    {
        $project = VideoProject::findOrFail($projectId);
        $scene = $project->scenes()->firstOrFail();

        $sourceClass = match ($sourceType) {
            'property-photo' => PropertyPhoto::class,
            'video-recording' => VideoRecording::class,
            default => throw new \InvalidArgumentException("Invalid source type: {$sourceType}"),
        };

        $nextOrder = $scene->clips()->max('sort_order') + 1;

        $clip = $scene->clips()->create([
            'slot' => 0,
            'source_type' => $sourceClass,
            'source_id' => $sourceId,
            'in_point_ms' => 0,
            'out_point_ms' => $durationMs,
            'sort_order' => $nextOrder,
        ]);

        return json_encode([
            'id' => $clip->id,
            'sort_order' => $clip->sort_order,
        ]);
    }
}
