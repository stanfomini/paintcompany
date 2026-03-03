<?php

namespace App\AI\Tools;

use App\Models\PropertyPhoto;
use App\Models\User;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Tool as PrismTool;

class GetJobPhotos
{
    public function __construct(private User $user) {}

    public static function definition(User $user): PrismTool
    {
        $instance = new self($user);

        return Tool::as('get_job_photos')
            ->for('Get all photos associated with a painting job')
            ->withNumberParameter('job_id', 'The job ID to get photos for')
            ->using(fn (int $job_id): string => $instance->handle($job_id));
    }

    public function handle(int $jobId): string
    {
        $photos = PropertyPhoto::where('job_id', $jobId)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PropertyPhoto $photo) => [
                'id' => $photo->id,
                'url' => $photo->url,
                'caption' => $photo->caption,
                'category' => $photo->category,
            ])
            ->toArray();

        return json_encode($photos);
    }
}
