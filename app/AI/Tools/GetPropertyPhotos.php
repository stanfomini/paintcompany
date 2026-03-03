<?php

namespace App\AI\Tools;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Tool as PrismTool;

class GetPropertyPhotos
{
    public function __construct(private User $user) {}

    public static function definition(User $user): PrismTool
    {
        $instance = new self($user);

        return Tool::as('get_property_photos')
            ->for('Get all photos associated with a property')
            ->withNumberParameter('property_id', 'The property ID to get photos for')
            ->using(fn (int $property_id): string => $instance->handle($property_id));
    }

    public function handle(int $propertyId): string
    {
        $property = Property::findOrFail($propertyId);

        $photos = $property->photos
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
