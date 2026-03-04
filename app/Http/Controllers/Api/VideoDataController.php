<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoDataController extends Controller
{
    /**
     * Get video data for a property or listing.
     */
    public function show(Request $request, string $type, int $id): JsonResponse
    {
        if ($type === 'listing') {
            return $this->getListingData($id);
        }

        if ($type === 'property') {
            return $this->getPropertyData($id);
        }

        return response()->json(['error' => 'Invalid type. Use "listing" or "property".'], 400);
    }

    /**
     * Get video data for an agent listing (now a Property with agent_id).
     */
    protected function getListingData(int $id): JsonResponse
    {
        $property = Property::with(['photos', 'agent'])->whereNotNull('agent_id')->find($id);

        if (! $property) {
            return response()->json(['error' => 'Listing not found.'], 404);
        }

        $photos = $property->photos->map(fn ($photo) => [
            'url' => $photo->url,
            'caption' => $photo->caption,
            'category' => $photo->category,
        ])->toArray();

        return response()->json([
            'type' => 'listing',
            'id' => $property->id,
            'address' => $property->address,
            'city' => $property->city,
            'state' => $property->state,
            'zip' => $property->zip,
            'photos' => $photos,
            'estimate' => null,
            'scopeOfWork' => $property->description ?? 'Professional painting services for your property.',
            'completionWeeks' => 3,
            'companyName' => config('app.name'),
            'companyPhone' => config('services.company.phone', '(555) 123-4567'),
            'companyTagline' => config('services.company.tagline', 'Professional Painting Services'),
            'primaryColor' => '#1e3a5f',
            'accentColor' => '#10b981',
        ]);
    }

    /**
     * Get video data for a property.
     */
    protected function getPropertyData(int $id): JsonResponse
    {
        $property = Property::with(['photos', 'customer'])->find($id);

        if (! $property) {
            return response()->json(['error' => 'Property not found.'], 404);
        }

        $photos = $property->photos->map(fn ($photo) => [
            'url' => $photo->url,
            'caption' => $photo->caption,
            'category' => $photo->category,
        ])->toArray();

        return response()->json([
            'type' => 'property',
            'id' => $property->id,
            'address' => $property->address,
            'city' => $property->city,
            'state' => $property->state,
            'zip' => $property->zip,
            'photos' => $photos,
            'estimate' => null,
            'scopeOfWork' => $property->notes ?? 'Professional painting services for your property.',
            'completionWeeks' => 3,
            'companyName' => config('app.name'),
            'companyPhone' => config('services.company.phone', '(555) 123-4567'),
            'companyTagline' => config('services.company.tagline', 'Professional Painting Services'),
            'primaryColor' => '#1e3a5f',
            'accentColor' => '#10b981',
        ]);
    }
}
