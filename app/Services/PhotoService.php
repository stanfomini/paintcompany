<?php

namespace App\Services;

use App\Jobs\ProcessPhotoJob;
use App\Models\Customer;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\PhotoView;
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
        $disk = config('filesystems.default');
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

        // Set content_updated_at and mark as viewed by uploader
        $photo->update(['content_updated_at' => now()]);
        PhotoView::markViewed($photo, $user);

        // Log activity if photo is linked to a job
        $jobId = $meta['job_id'] ?? null;

        if ($jobId) {
            $job = Job::find($jobId);

            if ($job) {
                $category = $meta['category'] ?? 'general';
                JobActivity::log(
                    $job,
                    'photo_uploaded',
                    "{$user->name} uploaded a {$category} photo",
                    ['photo_id' => $photo->id, 'category' => $category]
                );
            }
        }

        ProcessPhotoJob::dispatch($photo);

        if ($jobId && isset($job) && $job) {
            \App\Events\PhotoUpdated::dispatch($job, $photo->id, 'uploaded', $user->id);
        }

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
        // Log activity before deletion if linked to a job
        if ($photo->job_id) {
            $job = Job::find($photo->job_id);
            $user = auth()->user();

            if ($job && $user) {
                JobActivity::log(
                    $job,
                    'photo_deleted',
                    "{$user->name} deleted a {$photo->category} photo",
                    ['category' => $photo->category, 'filename' => $photo->original_filename]
                );
            }
        }

        // Capture job reference before deletion for broadcasting
        $jobForBroadcast = ($photo->job_id) ? Job::find($photo->job_id) : null;
        $photoId = $photo->id;
        $actorId = auth()->id();

        $diskName = $photo->disk ?? config('filesystems.default');

        foreach (['path', 'original_path', 'thumbnail_path', 'medium_path', 'annotated_path'] as $field) {
            if ($photo->$field && Storage::disk($diskName)->exists($photo->$field)) {
                Storage::disk($diskName)->delete($photo->$field);
            }
        }

        $photo->delete();

        if ($jobForBroadcast) {
            \App\Events\PhotoUpdated::dispatch($jobForBroadcast, $photoId, 'deleted', $actorId);
        }
    }

    /**
     * Check if a user can upload photos to a property.
     */
    public function canUpload(User $user, Property $property, string $category = 'general', ?Job $job = null): bool
    {
        if ($user->isInternal() && $user->can('photos.upload')) {
            return true;
        }

        if ($user->type === 'crew') {
            return true;
        }

        if ($user->type === 'customer') {
            $customer = Customer::where('user_id', $user->id)->first();

            return $customer && $property->customer_id === $customer->id;
        }

        if ($user->type === 'agent') {
            return $property->agent_id === $user->id
                || $property->deals()->where('agent_id', $user->id)->exists();
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
            return true;
        }

        if ($user->type === 'customer') {
            $customer = Customer::where('user_id', $user->id)->first();

            return $customer && $photo->property && $photo->property->customer_id === $customer->id;
        }

        if ($user->type === 'agent') {
            return $photo->property
                && ($photo->property->agent_id === $user->id
                    || $photo->property->deals()->where('agent_id', $user->id)->exists());
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

        if ($user->type === 'agent') {
            return $photo->property
                && ($photo->property->agent_id === $user->id
                    || $photo->property->deals()->where('agent_id', $user->id)->exists());
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

        if ($user->type === 'crew') {
            return ['before', 'during', 'after', 'general'];
        }

        if ($user->type === 'customer') {
            return ['before', 'during', 'after', 'general'];
        }

        if ($user->type === 'agent') {
            return ['before', 'reference', 'general'];
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
