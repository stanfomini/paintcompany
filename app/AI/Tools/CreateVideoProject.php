<?php

namespace App\AI\Tools;

use App\Models\User;
use App\Models\VideoProject;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Tool as PrismTool;

class CreateVideoProject
{
    public function __construct(private User $user) {}

    public static function definition(User $user): PrismTool
    {
        $instance = new self($user);

        return Tool::as('create_video_project')
            ->for('Create a new video project for AI-generated content')
            ->withStringParameter('title', 'Title for the video project')
            ->withStringParameter('aspect_ratio', 'Aspect ratio (9:16 for vertical, 16:9 for landscape)', required: false)
            ->using(fn (string $title, string $aspect_ratio = '9:16'): string => $instance->handle($title, $aspect_ratio));
    }

    public function handle(string $title, string $aspectRatio = '9:16'): string
    {
        $project = VideoProject::create([
            'user_id' => $this->user->id,
            'title' => $title,
            'creation_mode' => 'ai_generated',
            'aspect_ratio' => $aspectRatio,
            'status' => 'draft',
        ]);

        $project->scenes()->create([
            'sort_order' => 0,
            'name' => 'Scene 1',
            'layout' => 'solo',
        ]);

        return json_encode([
            'id' => $project->id,
            'title' => $project->title,
            'status' => $project->status,
        ]);
    }
}
