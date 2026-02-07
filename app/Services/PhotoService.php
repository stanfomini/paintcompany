<?php

namespace App\Services;

use App\Jobs\ProcessPhotoJob;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PhotoService
{
    /**
     * Upload a photo to a property.
     *
     * @param  array{estimate_id?: int, job_id?: int, estimate_area_id?: int, category?: string, caption?: string, show_on_proposal?: bool}  $meta
     */
    public function upload(UploadedFile $file, Property $property, User $user, array $meta = []): PropertyPhoto
    {
        $uuid = Str::uuid();
        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $disk = config('filesystems.photos_disk', 'public');
        $basePath = "photos/properties/{$property->id}/originals";
        $filename = "{$uuid}.{$ext}";
        $storagePath = $file->storeAs($basePath, $filename, $disk);

        $photo = PropertyPhoto::create([
            'property_id' => $property->id,
            'uploaded_by' => $user->id,
            'path' => $storagePath,
            'filename' => $filename,
            'disk' => $disk,
            'original_path' => $storagePath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'category' => $meta['category'] ?? 'general',
            'caption' => $meta['caption'] ?? null,
            'show_on_proposal' => $meta['show_on_proposal'] ?? false,
            'estimate_id' => $meta['estimate_id'] ?? null,
            'estimate_area_id' => $meta['estimate_area_id'] ?? null,
            'job_id' => $meta['job_id'] ?? null,
            'processing_status' => 'pending',
            'sort_order' => PropertyPhoto::where('property_id', $property->id)->max('sort_order') + 1,
        ]);

        ProcessPhotoJob::dispatch($photo);

        return $photo;
    }

    /**
     * Upload multiple photos to a property.
     *
     * @param  array<UploadedFile>  $files
     * @param  array{estimate_id?: int, job_id?: int, estimate_area_id?: int, category?: string, caption?: string, show_on_proposal?: bool}  $meta
     */
    public function uploadMultiple(array $files, Property $property, User $user, array $meta = []): Collection
    {
        $photos = collect();

        foreach ($files as $file) {
            $photos->push($this->upload($file, $property, $user, $meta));
        }

        return $photos;
    }

    /**
     * Delete a photo and all its file variants from storage.
     */
    public function delete(PropertyPhoto $photo): void
    {
        $disk = $photo->disk ?? 'public';

        foreach (['path', 'original_path', 'thumbnail_path', 'medium_path', 'annotated_path'] as $field) {
            if ($photo->$field && Storage::disk($disk)->exists($photo->$field)) {
                Storage::disk($disk)->delete($photo->$field);
            }
        }

        $photo->delete();
    }

    /**
     * Check if a user can upload photos to a property.
     */
    public function canUpload(User $user, Property $property, string $category = 'general', ?Job $job = null): bool
    {
        if ($user->isInternal() && $user->can('photos.upload')) {
            return true;
        }

        if ($user->type === 'crew' && $job && $category !== 'before') {
            return $this->isCrewAssignedToJob($user, $job)
                && in_array($job->stage, ['in_progress', 'completed']);
        }

        if ($user->type === 'customer') {
            $customer = Customer::where('user_id', $user->id)->first();

            if (! $customer || $property->customer_id !== $customer->id) {
                return false;
            }

            if ($category === 'before' && (! $job || $job->stage === 'estimate_accepted')) {
                return true;
            }

            if ($category === 'after' && $job && $job->stage === 'completed') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a user can delete a photo.
     */
    public function canDelete(User $user, PropertyPhoto $photo): bool
    {
        if ($user->isInternal() && $user->can('photos.delete')) {
            return true;
        }

        return $photo->uploaded_by === $user->id;
    }

    /**
     * Check if a user can annotate a photo.
     */
    public function canAnnotate(User $user, PropertyPhoto $photo): bool
    {
        if ($user->isInternal() && $user->can('photos.annotate')) {
            return true;
        }

        if ($user->type === 'crew') {
            $job = $photo->job;

            return $job && $this->isCrewAssignedToJob($user, $job);
        }

        return false;
    }

    /**
     * Check if a user can comment on a photo.
     */
    public function canComment(User $user, PropertyPhoto $photo): bool
    {
        if ($user->isInternal() && $user->can('photos.comment')) {
            return true;
        }

        if ($user->type === 'crew') {
            $job = $photo->job;

            return $job && $this->isCrewAssignedToJob($user, $job);
        }

        if ($user->type === 'customer') {
            $customer = Customer::where('user_id', $user->id)->first();

            return $customer && $photo->property->customer_id === $customer->id;
        }

        return false;
    }

    /**
     * Get the upload categories allowed for a user.
     *
     * @return array<string>
     */
    public function getAllowedUploadCategories(User $user, ?Job $job = null): array
    {
        if ($user->isInternal()) {
            return ['before', 'during', 'after', 'reference', 'general'];
        }

        if ($user->type === 'crew' && $job) {
            $categories = [];

            if ($job->stage === 'in_progress') {
                $categories[] = 'during';
            }

            if (in_array($job->stage, ['in_progress', 'completed'])) {
                $categories[] = 'after';
            }

            return $categories;
        }

        if ($user->type === 'customer') {
            if (! $job || $job->stage === 'estimate_accepted') {
                return ['before'];
            }

            if ($job->stage === 'completed') {
                return ['after'];
            }
        }

        return [];
    }

    /**
     * Get photos visible to a user for a given property.
     */
    public function getVisiblePhotos(User $user, Property $property, ?Job $job = null, ?int $estimateId = null): Collection
    {
        $query = PropertyPhoto::where('property_id', $property->id);

        if ($estimateId) {
            $query->where(function ($q) use ($estimateId) {
                $q->where('estimate_id', $estimateId)->orWhereNull('estimate_id');
            });
        }

        if ($job) {
            $query->where(function ($q) use ($job) {
                $q->where('job_id', $job->id)->orWhereNull('job_id');
            });
        }

        return $query->orderBy('sort_order')->get();
    }

    /**
     * Reorder photos for a property.
     *
     * @param  array<int>  $orderedIds
     */
    public function reorder(Property $property, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            PropertyPhoto::where('id', $id)
                ->where('property_id', $property->id)
                ->update(['sort_order' => $index]);
        }
    }

    /**
     * Check if a crew user is assigned to a job.
     */
    private function isCrewAssignedToJob(User $user, Job $job): bool
    {
        return $job->crews()->where('crew_user_id', $user->id)->exists();
    }
}
