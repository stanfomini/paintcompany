<?php

namespace App\Services;

use App\Models\PhotoAnnotation;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class AnnotationService
{
    /**
     * Save or update an annotation for a photo.
     *
     * @param  array<string, mixed>  $canvasJson
     */
    public function save(PropertyPhoto $photo, array $canvasJson, User $user): PhotoAnnotation
    {
        return PhotoAnnotation::updateOrCreate(
            ['property_photo_id' => $photo->id],
            [
                'canvas_json' => $canvasJson,
                'created_by' => $user->id,
                'canvas_version' => '6.0',
            ]
        );
    }

    /**
     * Clear an annotation and its rendered file.
     */
    public function clearAnnotation(PropertyPhoto $photo): void
    {
        $photo->annotation()?->delete();

        if ($photo->annotated_path) {
            $disk = $photo->disk ?? 'public';
            Storage::disk($disk)->delete($photo->annotated_path);
            $photo->update(['annotated_path' => null]);
        }
    }
}
