<?php

namespace App\AI\Tools;

use App\Models\Deal;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoRecording;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Tool as PrismTool;

class GetDealMedia
{
    public function __construct(private User $user) {}

    public static function definition(User $user): PrismTool
    {
        $instance = new self($user);

        return Tool::as('get_deal_media')
            ->for('Get all photos and videos associated with a deal')
            ->withNumberParameter('deal_id', 'The deal ID to get media for')
            ->using(fn (int $deal_id): string => $instance->handle($deal_id));
    }

    public function handle(int $dealId): string
    {
        $deal = Deal::with(['property.photos', 'estimates.photos', 'job'])->findOrFail($dealId);

        $photos = collect();

        if ($deal->property) {
            $photos = $photos->merge($deal->property->photos);
        }

        foreach ($deal->estimates as $estimate) {
            $photos = $photos->merge($estimate->photos);
        }

        $photos = $photos->unique('id')->map(fn (PropertyPhoto $photo) => [
            'id' => $photo->id,
            'url' => $photo->url,
            'caption' => $photo->caption,
            'category' => $photo->category,
        ])->values()->toArray();

        $videos = collect();

        foreach ($deal->estimates as $estimate) {
            $videos = $videos->merge($estimate->videoRecordings);
        }

        if ($deal->job) {
            $videos = $videos->merge($deal->job->videoRecordings);
        }

        $videos = $videos->unique('id')->map(fn (VideoRecording $video) => [
            'id' => $video->id,
            'title' => $video->title,
            'thumbnail_url' => $video->getThumbnailUrl(),
            'duration' => $video->duration_seconds,
            'status' => $video->status,
        ])->values()->toArray();

        return json_encode(['photos' => $photos, 'videos' => $videos]);
    }
}
