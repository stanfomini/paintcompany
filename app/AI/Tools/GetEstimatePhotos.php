<?php

namespace App\AI\Tools;

use App\Models\Estimate;
use App\Models\PropertyPhoto;
use App\Models\User;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Tool as PrismTool;

class GetEstimatePhotos
{
    public function __construct(private User $user) {}

    public static function definition(User $user): PrismTool
    {
        $instance = new self($user);

        return Tool::as('get_estimate_photos')
            ->for('Get all photos associated with an estimate')
            ->withNumberParameter('estimate_id', 'The estimate ID to get photos for')
            ->using(fn (int $estimate_id): string => $instance->handle($estimate_id));
    }

    public function handle(int $estimateId): string
    {
        $estimate = Estimate::findOrFail($estimateId);

        $photos = $estimate->photos
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
