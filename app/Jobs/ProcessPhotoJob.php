<?php

namespace App\Jobs;

use App\Models\PropertyPhoto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessPhotoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public PropertyPhoto $photo) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $photo = $this->photo;
        $disk = $photo->disk ?? 'public';
        $originalPath = $photo->original_path ?? $photo->path;

        if (! $originalPath || ! Storage::disk($disk)->exists($originalPath)) {
            $photo->update(['processing_status' => 'failed']);

            return;
        }

        try {
            $fullPath = Storage::disk($disk)->path($originalPath);
            $imageInfo = @getimagesize($fullPath);

            if (! $imageInfo) {
                $photo->update(['processing_status' => 'complete']);

                return;
            }

            $updates = [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
            ];

            // Extract EXIF data
            $exif = $this->extractExif($fullPath, $photo->mime_type);
            if ($exif) {
                $updates = array_merge($updates, $exif);
            }

            // Generate thumbnail (400x400 center crop)
            $thumbnailPath = $this->generateVariant($fullPath, $disk, $photo, 'thumbnail', 400, 400, true);
            if ($thumbnailPath) {
                $updates['thumbnail_path'] = $thumbnailPath;
            }

            // Generate medium (1200px wide, proportional height)
            $mediumPath = $this->generateVariant($fullPath, $disk, $photo, 'medium', 1200, null, false);
            if ($mediumPath) {
                $updates['medium_path'] = $mediumPath;
            }

            $updates['processing_status'] = 'complete';
            $photo->update($updates);
        } catch (\Throwable $e) {
            Log::error('ProcessPhotoJob failed', [
                'photo_id' => $photo->id,
                'error' => $e->getMessage(),
            ]);

            $photo->update(['processing_status' => 'failed']);
        }
    }

    /**
     * Extract EXIF data from an image file.
     *
     * @return array{taken_at?: string, latitude?: float, longitude?: float}|null
     */
    private function extractExif(string $fullPath, ?string $mimeType): ?array
    {
        if (! function_exists('exif_read_data') || ! in_array($mimeType, ['image/jpeg', 'image/tiff'])) {
            return null;
        }

        $exif = @exif_read_data($fullPath);

        if (! $exif) {
            return null;
        }

        $data = [];

        if (! empty($exif['DateTimeOriginal'])) {
            $data['taken_at'] = date('Y-m-d H:i:s', strtotime($exif['DateTimeOriginal']));
        }

        if (! empty($exif['GPSLatitude']) && ! empty($exif['GPSLongitude'])) {
            $lat = $this->gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
            $lng = $this->gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'W');

            if ($lat !== null && $lng !== null) {
                $data['latitude'] = $lat;
                $data['longitude'] = $lng;
            }
        }

        return $data ?: null;
    }

    /**
     * Convert GPS EXIF coordinates to decimal degrees.
     */
    private function gpsToDecimal(array $coordinate, string $hemisphere): ?float
    {
        if (count($coordinate) < 3) {
            return null;
        }

        $degrees = $this->fractionToFloat($coordinate[0]);
        $minutes = $this->fractionToFloat($coordinate[1]);
        $seconds = $this->fractionToFloat($coordinate[2]);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if (in_array($hemisphere, ['S', 'W'])) {
            $decimal *= -1;
        }

        return round($decimal, 7);
    }

    /**
     * Convert an EXIF fraction string to a float.
     */
    private function fractionToFloat(string $fraction): ?float
    {
        $parts = explode('/', $fraction);

        if (count($parts) === 2 && (float) $parts[1] !== 0.0) {
            return (float) $parts[0] / (float) $parts[1];
        }

        return is_numeric($fraction) ? (float) $fraction : null;
    }

    /**
     * Generate a resized variant using GD.
     */
    private function generateVariant(string $sourcePath, string $disk, PropertyPhoto $photo, string $variant, int $targetWidth, ?int $targetHeight, bool $crop): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $sourceImage = $this->createImageFromFile($sourcePath, $photo->mime_type);

        if (! $sourceImage) {
            return null;
        }

        $srcWidth = imagesx($sourceImage);
        $srcHeight = imagesy($sourceImage);

        if ($crop && $targetHeight) {
            // Center crop to exact dimensions
            $srcRatio = $srcWidth / $srcHeight;
            $targetRatio = $targetWidth / $targetHeight;

            if ($srcRatio > $targetRatio) {
                $cropHeight = $srcHeight;
                $cropWidth = (int) ($srcHeight * $targetRatio);
                $cropX = (int) (($srcWidth - $cropWidth) / 2);
                $cropY = 0;
            } else {
                $cropWidth = $srcWidth;
                $cropHeight = (int) ($srcWidth / $targetRatio);
                $cropX = 0;
                $cropY = (int) (($srcHeight - $cropHeight) / 2);
            }

            $resized = imagecreatetruecolor($targetWidth, $targetHeight);
            imagecopyresampled($resized, $sourceImage, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        } else {
            // Proportional resize
            if ($srcWidth <= $targetWidth) {
                imagedestroy($sourceImage);

                return null;
            }

            $newWidth = $targetWidth;
            $newHeight = (int) ($srcHeight * ($targetWidth / $srcWidth));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
        }

        $directory = dirname($photo->original_path ?? $photo->path);
        $variantDir = str_replace('/originals', "/{$variant}s", $directory);
        $variantPath = "{$variantDir}/{$photo->filename}";

        // Write to a temp file then store
        $tmpFile = tempnam(sys_get_temp_dir(), 'photo_');
        imagejpeg($resized, $tmpFile, 85);

        Storage::disk($disk)->put($variantPath, file_get_contents($tmpFile));

        unlink($tmpFile);
        imagedestroy($sourceImage);
        imagedestroy($resized);

        return $variantPath;
    }

    /**
     * Create a GD image resource from a file path.
     */
    private function createImageFromFile(string $path, ?string $mimeType): \GdImage|false
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }
}
