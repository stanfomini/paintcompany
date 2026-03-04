<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

trait HandlesAvatarUpload
{
    public ?TemporaryUploadedFile $avatar = null;

    public function updatedAvatar(): void
    {
        $this->validateOnly('avatar', [
            'avatar' => ['image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);
    }

    public function saveAvatar(User $user): void
    {
        if (! $this->avatar) {
            return;
        }

        // Delete old avatar if exists
        if ($user->avatar_path) {
            Storage::disk(config('filesystems.default'))->delete($user->avatar_path);
        }

        $path = $this->avatar->store('avatars', config('filesystems.default'));

        // Auto-resize to 256x256 using GD
        $this->resizeAvatar($path);

        $user->update(['avatar_path' => $path]);

        $this->reset('avatar');
    }

    public function removeAvatar(): void
    {
        $user = $this->getAuthenticatedUser();

        $user->deleteAvatar();
    }

    /**
     * Resize the avatar to a square 256x256 image using GD.
     */
    protected function resizeAvatar(string $path): void
    {
        $fullPath = Storage::disk(config('filesystems.default'))->path($path);

        if (! file_exists($fullPath)) {
            return;
        }

        $imageInfo = @getimagesize($fullPath);
        if (! $imageInfo) {
            return;
        }

        $mime = $imageInfo['mime'];
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($fullPath),
            'image/png' => @imagecreatefrompng($fullPath),
            'image/webp' => @imagecreatefromwebp($fullPath),
            default => null,
        };

        if (! $source) {
            return;
        }

        $size = 256;
        $width = imagesx($source);
        $height = imagesy($source);

        // Already small enough and square - skip
        if ($width <= $size && $height <= $size && $width === $height) {
            imagedestroy($source);

            return;
        }

        // Center-crop to square, then resize
        $cropSize = min($width, $height);
        $cropX = (int) (($width - $cropSize) / 2);
        $cropY = (int) (($height - $cropSize) / 2);

        $resized = imagecreatetruecolor($size, $size);

        // Preserve transparency for PNG/WebP
        if (in_array($mime, ['image/png', 'image/webp'])) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $source, 0, 0, $cropX, $cropY, $size, $size, $cropSize, $cropSize);

        // Save as JPEG for consistent output
        imagejpeg($resized, $fullPath, 90);

        imagedestroy($source);
        imagedestroy($resized);
    }

    /**
     * Get the authenticated user for avatar operations.
     * Override in components that use non-default guards.
     */
    protected function getAuthenticatedUser(): User
    {
        return auth()->user();
    }
}
