<?php

namespace App\AI\Tools;

use App\Models\User;
use App\Models\VideoProject;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Tool as PrismTool;

class AddTextOverlay
{
    public function __construct(private User $user) {}

    public static function definition(User $user): PrismTool
    {
        $instance = new self($user);

        return Tool::as('add_text_overlay')
            ->for('Add a text overlay to a video project scene')
            ->withNumberParameter('project_id', 'The video project ID')
            ->withStringParameter('content', 'The text content to display')
            ->withStringParameter('position', 'Position: top, center, or bottom', required: false)
            ->using(fn (int $project_id, string $content, string $position = 'bottom'): string => $instance->handle($project_id, $content, $position));
    }

    public function handle(int $projectId, string $content, string $position = 'bottom'): string
    {
        $project = VideoProject::findOrFail($projectId);
        $scene = $project->scenes()->firstOrFail();

        $positionY = match ($position) {
            'top' => 0.1,
            'center' => 0.5,
            default => 0.85,
        };

        $nextOrder = $scene->overlays()->max('sort_order') + 1;

        $overlay = $scene->overlays()->create([
            'type' => 'text',
            'content' => $content,
            'position_x' => 0.5,
            'position_y' => $positionY,
            'width' => 0.8,
            'height' => 0.1,
            'sort_order' => $nextOrder,
        ]);

        return json_encode([
            'id' => $overlay->id,
        ]);
    }
}
