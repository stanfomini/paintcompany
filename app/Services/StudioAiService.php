<?php

namespace App\Services;

use App\AI\Tools\AddClipToProject;
use App\AI\Tools\AddTextOverlay;
use App\AI\Tools\CreateVideoProject;
use App\AI\Tools\GetDealMedia;
use App\AI\Tools\GetEstimatePhotos;
use App\AI\Tools\GetJobPhotos;
use App\AI\Tools\GetPropertyPhotos;
use App\AI\Tools\SearchDeals;
use App\Models\CompanySetting;
use App\Models\User;
use App\Models\VideoProject;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;

class StudioAiService
{
    public function __construct(private User $user) {}

    /**
     * Generate a video project from a natural language prompt.
     * AI will search deals, find media, and assemble a project.
     */
    public function generateFromPrompt(string $prompt): VideoProject
    {
        $response = $this->runAgent($prompt);

        return VideoProject::where('user_id', $this->user->id)
            ->where('creation_mode', 'ai_generated')
            ->latest()
            ->firstOrFail();
    }

    /**
     * Run the Prism agent with tools and return the raw response.
     */
    public function runAgent(string $prompt): TextResponse
    {
        return Prism::text()
            ->using(Provider::Anthropic, 'claude-sonnet-4-20250514')
            ->withSystemPrompt($this->buildSystemPrompt())
            ->withTools($this->getToolDefinitions())
            ->withMaxSteps(10)
            ->withPrompt($prompt)
            ->asText();
    }

    /**
     * AI-assisted editing: interpret an instruction and apply changes.
     */
    public function assistEdit(VideoProject $project, string $instruction): array
    {
        return [
            'action' => 'assist',
            'instruction' => $instruction,
            'project_id' => $project->id,
            'status' => 'pending',
        ];
    }

    /**
     * Get list of registered tool names.
     */
    public function getRegisteredTools(): array
    {
        return [
            'search_deals',
            'get_deal_media',
            'get_job_photos',
            'get_estimate_photos',
            'get_property_photos',
            'create_video_project',
            'add_clip_to_project',
            'add_text_overlay',
        ];
    }

    /**
     * Build Prism tool definitions from our tool classes.
     *
     * @return array<int, Tool>
     */
    public function getToolDefinitions(): array
    {
        return [
            SearchDeals::definition($this->user),
            GetDealMedia::definition($this->user),
            GetJobPhotos::definition($this->user),
            GetEstimatePhotos::definition($this->user),
            GetPropertyPhotos::definition($this->user),
            CreateVideoProject::definition($this->user),
            AddClipToProject::definition($this->user),
            AddTextOverlay::definition($this->user),
        ];
    }

    /**
     * Build the system prompt for the AI agent.
     */
    private function buildSystemPrompt(): string
    {
        $company = CompanySetting::getInstance();

        return <<<PROMPT
        You are a video studio assistant for {$company->company_name}, a painting company.
        You help create short-form video projects by finding relevant media from the system.

        When asked to create a video:
        1. Search for relevant deals, jobs, or estimates
        2. Get photos and videos from those deals
        3. Create a video project with an appropriate title
        4. Add the best photos/videos as clips
        5. Add text overlays for branding and context

        Always include the company name as a text overlay.
        For before/after videos, find photos categorized as 'before' and 'after'.
        Keep videos short - aim for 5-8 clips max.
        Default to 9:16 aspect ratio (vertical/TikTok style).
        PROMPT;
    }
}
