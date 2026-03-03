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
use App\Models\Deal;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
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
     * Fill a template-based project with media from system data.
     * Reads structure_json segments and auto-fills clips from the associated deal.
     */
    public function fillTemplate(VideoProject $project): void
    {
        $template = $project->template;
        if (! $template) {
            return;
        }

        $dealId = $project->metadata['deal_id'] ?? null;
        if (! $dealId) {
            return;
        }

        $deal = Deal::with(['property.photos', 'estimates.photos', 'job.videoRecordings'])->find($dealId);
        if (! $deal) {
            return;
        }

        $media = $this->collectDealMedia($deal);
        $this->fillSceneClips($project, $template, $media);
        $this->fillTemplateOverlays($project, $template, $deal);
    }

    /**
     * Collect all categorized media from a deal.
     *
     * @return array{before: \Illuminate\Support\Collection, after: \Illuminate\Support\Collection, all: \Illuminate\Support\Collection}
     */
    private function collectDealMedia(Deal $deal): array
    {
        $allPhotos = collect();
        $beforePhotos = collect();
        $afterPhotos = collect();

        if ($deal->property) {
            $allPhotos = $deal->property->photos;
            $beforePhotos = $allPhotos->where('category', 'before');
            $afterPhotos = $allPhotos->where('category', 'after');
        }

        foreach ($deal->estimates as $estimate) {
            $estimatePhotos = $estimate->photos;
            $allPhotos = $allPhotos->merge($estimatePhotos);
            $beforePhotos = $beforePhotos->merge($estimatePhotos->where('category', 'before'));
            $afterPhotos = $afterPhotos->merge($estimatePhotos->where('category', 'after'));
        }

        return [
            'before' => $beforePhotos->unique('id'),
            'after' => $afterPhotos->unique('id'),
            'all' => $allPhotos->unique('id'),
        ];
    }

    /**
     * Fill scene clips based on template segment auto_fill rules.
     */
    private function fillSceneClips(VideoProject $project, \App\Models\StudioTemplate $template, array $media): void
    {
        $segments = $template->structure_json['segments'] ?? [];
        $scenes = $project->scenes()->orderBy('sort_order')->get();

        foreach ($scenes as $index => $scene) {
            $segment = $segments[$index] ?? null;
            if (! $segment || ($segment['type'] ?? null) !== 'media') {
                continue;
            }

            $autoFill = $segment['auto_fill'] ?? null;
            if (! $autoFill) {
                continue;
            }

            $mediaToAdd = match ($autoFill) {
                'before_photos' => $media['before']->take(3),
                'after_photos' => $media['after']->take(3),
                'all_photos' => $media['all']->take(5),
                'first_photo' => $media['all']->take(1),
                'last_photo' => $media['all']->reverse()->take(1),
                'best_photo' => $media['all']->take(1),
                'estimate_photos' => $media['all']->take(5),
                'recent_completions' => $media['after']->take(5),
                default => collect(),
            };

            foreach ($mediaToAdd->values() as $sortOrder => $photo) {
                $scene->clips()->create([
                    'slot' => 'main',
                    'source_type' => PropertyPhoto::class,
                    'source_id' => $photo->id,
                    'sort_order' => $sortOrder,
                    'volume' => 100,
                    'fit_mode' => 'cover',
                ]);
            }
        }
    }

    /**
     * Fill overlays from template overlay definitions.
     */
    private function fillTemplateOverlays(VideoProject $project, \App\Models\StudioTemplate $template, Deal $deal): void
    {
        $overlayDefs = $template->structure_json['overlays'] ?? [];
        $scenes = $project->scenes()->orderBy('sort_order')->get();
        $firstScene = $scenes->first();

        if (! $firstScene || empty($overlayDefs)) {
            return;
        }

        $company = CompanySetting::getInstance();

        foreach ($overlayDefs as $overlayDef) {
            $content = match ($overlayDef['auto_fill'] ?? null) {
                'company_name' => $company->company_name ?? '',
                'company_phone' => $company->company_phone ?? '',
                'customer_name' => $deal->customer?->full_name ?? '',
                'estimate_total' => $deal->estimates->first()?->total
                    ? '$'.number_format((float) $deal->estimates->first()->total, 0)
                    : '',
                default => $overlayDef['content'] ?? '',
            };

            if ($content === '') {
                continue;
            }

            $targetScene = isset($overlayDef['segment_index']) && isset($scenes[$overlayDef['segment_index']])
                ? $scenes[$overlayDef['segment_index']]
                : $firstScene;

            $targetScene->overlays()->create([
                'type' => $overlayDef['type'] ?? 'text',
                'content' => $content,
                'sort_order' => $targetScene->overlays()->count(),
            ]);
        }
    }

    /**
     * Generate auto-captions for video clips using Whisper transcription.
     * Creates timed text overlays on the project's scenes.
     *
     * @return array<int, array{text: string, start: float, end: float, scene_id: int}> Generated caption segments
     */
    public function generateCaptions(VideoProject $project): array
    {
        $python = app(PythonBridgeService::class);

        if (! $python->isEnabled()) {
            return [];
        }

        $captions = [];
        $scenes = $project->scenes()->with('clips.source')->orderBy('sort_order')->get();

        foreach ($scenes as $scene) {
            foreach ($scene->clips as $clip) {
                if ($clip->source_type !== VideoRecording::class) {
                    continue;
                }

                $recording = $clip->source;
                if (! $recording || ! $recording->isReady()) {
                    continue;
                }

                $audioUrl = $recording->getVideoUrl() ?? $recording->getStreamUrl();
                if (! $audioUrl) {
                    continue;
                }

                try {
                    $result = $python->transcribeAudio($audioUrl);

                    if (! isset($result['segments'])) {
                        continue;
                    }

                    foreach ($result['segments'] as $segment) {
                        $text = trim($segment['text'] ?? '');
                        if ($text === '') {
                            continue;
                        }

                        $scene->overlays()->create([
                            'type' => 'caption',
                            'content' => $text,
                            'sort_order' => $scene->overlays()->count(),
                        ]);

                        $captions[] = [
                            'text' => $text,
                            'start' => $segment['start'] ?? 0,
                            'end' => $segment['end'] ?? 0,
                            'scene_id' => $scene->id,
                        ];
                    }
                } catch (\Exception $e) {
                    report($e);

                    continue;
                }
            }
        }

        return $captions;
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
