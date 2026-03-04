<?php

namespace App\Services;

use App\Models\Job;
use App\Models\JobActivity;
use App\Models\PhotoAnnotation;
use App\Models\PhotoView;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class AnnotationService
{
    /**
     * Save or update an annotation for a photo by author type.
     *
     * @param  array<string, mixed>  $canvasJson
     */
    public function save(PropertyPhoto $photo, array $canvasJson, User $user): PhotoAnnotation
    {
        $authorType = PhotoAnnotation::resolveAuthorType($user);

        $annotation = PhotoAnnotation::updateOrCreate(
            [
                'property_photo_id' => $photo->id,
                'author_type' => $authorType,
            ],
            [
                'canvas_json' => $canvasJson,
                'created_by' => $user->id,
                'canvas_version' => '6.0',
            ]
        );

        // Update content timestamp and mark as viewed by annotator
        $photo->update(['content_updated_at' => now()]);
        PhotoView::markViewed($photo, $user);

        // Log job activity and broadcast
        if ($photo->job_id) {
            $job = Job::find($photo->job_id);

            if ($job) {
                $label = PhotoAnnotation::AUTHOR_TYPES[$authorType] ?? $authorType;
                JobActivity::log(
                    $job,
                    'photo_annotated',
                    "{$user->name} ({$label}) annotated a photo",
                    ['photo_id' => $photo->id, 'author_type' => $authorType]
                );

                \App\Events\PhotoUpdated::dispatch($job, $photo->id, 'annotated', $user->id);
            }
        }

        return $annotation;
    }

    /**
     * Clear a specific author type's annotation or all annotations.
     */
    public function clearAnnotation(PropertyPhoto $photo, ?string $authorType = null, ?User $user = null): void
    {
        $disk = $photo->disk ?? config('filesystems.default');

        if ($authorType) {
            $annotation = $photo->annotations()->forAuthorType($authorType)->first();

            if ($annotation) {
                if ($annotation->rendered_path) {
                    Storage::disk($disk)->delete($annotation->rendered_path);
                }
                $annotation->delete();
            }
        } else {
            foreach ($photo->annotations as $annotation) {
                if ($annotation->rendered_path) {
                    Storage::disk($disk)->delete($annotation->rendered_path);
                }
            }
            $photo->annotations()->delete();
        }

        // If there are remaining annotations, re-render composite, otherwise clear
        $photo->refresh();

        if ($photo->annotations()->exists()) {
            $this->renderComposite($photo);
        } elseif ($photo->annotated_path) {
            Storage::disk($disk)->delete($photo->annotated_path);
            $photo->update(['annotated_path' => null]);
        }

        if ($photo->job_id) {
            $job = Job::find($photo->job_id);
            $actorId = $user?->id ?? auth()->id();

            if ($job) {
                \App\Events\PhotoUpdated::dispatch($job, $photo->id, 'annotated', $actorId);
            }
        }
    }

    /**
     * Render the composite annotated image (all layers combined).
     */
    public function renderComposite(PropertyPhoto $photo): void
    {
        $annotations = $photo->annotations()->with('createdBy')->get();

        if ($annotations->isEmpty()) {
            return;
        }

        $disk = $photo->disk ?? config('filesystems.default');
        $originalPath = $photo->medium_path ?? $photo->original_path ?? $photo->path;

        if (! $originalPath || ! Storage::disk($disk)->exists($originalPath)) {
            return;
        }

        // Download to temp file for GD processing
        $tempFile = tempnam(sys_get_temp_dir(), 'annot_src_');
        file_put_contents($tempFile, Storage::disk($disk)->get($originalPath));

        $sourceImage = $this->createImageFromFile($tempFile, $photo->mime_type);
        @unlink($tempFile);

        if (! $sourceImage) {
            return;
        }

        $imgWidth = imagesx($sourceImage);
        $imgHeight = imagesy($sourceImage);

        // Draw all annotation layers onto the composite
        foreach ($annotations as $annotation) {
            $canvasJson = $annotation->canvas_json;
            $canvasWidth = $canvasJson['width'] ?? $imgWidth;
            $canvasHeight = $canvasJson['height'] ?? $imgHeight;
            $scaleX = $imgWidth / $canvasWidth;
            $scaleY = $imgHeight / $canvasHeight;

            foreach ($canvasJson['objects'] ?? [] as $obj) {
                $this->drawFabricObject($sourceImage, $obj, $scaleX, $scaleY);
            }

            // Draw author type label in corner
            $this->drawAuthorLabel($sourceImage, $annotation, $imgWidth);
        }

        // Save composite
        $directory = dirname($photo->original_path ?? $photo->path);
        $annotatedDir = str_replace('/originals', '/annotated', $directory);
        $annotatedPath = "{$annotatedDir}/{$photo->filename}";

        $tmpFile = tempnam(sys_get_temp_dir(), 'annotated_');

        try {
            imagejpeg($sourceImage, $tmpFile, 90);
            Storage::disk($disk)->put($annotatedPath, file_get_contents($tmpFile));
        } finally {
            @unlink($tmpFile);
            imagedestroy($sourceImage);
        }

        $photo->update(['annotated_path' => $annotatedPath]);
    }

    /**
     * Render a single annotation layer.
     */
    public function renderLayer(PropertyPhoto $photo, PhotoAnnotation $annotation): void
    {
        $disk = $photo->disk ?? config('filesystems.default');
        $originalPath = $photo->medium_path ?? $photo->original_path ?? $photo->path;

        if (! $originalPath || ! Storage::disk($disk)->exists($originalPath)) {
            return;
        }

        // Download to temp file for GD processing
        $tempFile = tempnam(sys_get_temp_dir(), 'annot_layer_');
        file_put_contents($tempFile, Storage::disk($disk)->get($originalPath));

        $sourceImage = $this->createImageFromFile($tempFile, $photo->mime_type);
        @unlink($tempFile);

        if (! $sourceImage) {
            return;
        }

        $imgWidth = imagesx($sourceImage);
        $imgHeight = imagesy($sourceImage);
        $canvasJson = $annotation->canvas_json;
        $canvasWidth = $canvasJson['width'] ?? $imgWidth;
        $canvasHeight = $canvasJson['height'] ?? $imgHeight;
        $scaleX = $imgWidth / $canvasWidth;
        $scaleY = $imgHeight / $canvasHeight;

        foreach ($canvasJson['objects'] ?? [] as $obj) {
            $this->drawFabricObject($sourceImage, $obj, $scaleX, $scaleY);
        }

        // Draw author label
        $this->drawAuthorLabel($sourceImage, $annotation, $imgWidth);

        // Save per-layer image
        $directory = dirname($photo->original_path ?? $photo->path);
        $layerDir = str_replace('/originals', '/annotated', $directory);
        $layerPath = "{$layerDir}/{$annotation->author_type}_{$photo->filename}";

        $tmpFile = tempnam(sys_get_temp_dir(), 'layer_');

        try {
            imagejpeg($sourceImage, $tmpFile, 90);
            Storage::disk($disk)->put($layerPath, file_get_contents($tmpFile));
        } finally {
            @unlink($tmpFile);
            imagedestroy($sourceImage);
        }

        $annotation->update(['rendered_path' => $layerPath]);
    }

    /**
     * Draw an author type label on the rendered image.
     */
    private function drawAuthorLabel(\GdImage $image, PhotoAnnotation $annotation, int $imgWidth): void
    {
        $label = $annotation->author_type_label;
        $userName = $annotation->createdBy?->name;
        $text = $userName ? "{$label}: {$userName}" : $label;
        $colorHex = ltrim($annotation->author_color, '#');
        $r = hexdec(substr($colorHex, 0, 2));
        $g = hexdec(substr($colorHex, 2, 2));
        $b = hexdec(substr($colorHex, 4, 2));

        // Draw label background
        $font = 4;
        $textWidth = imagefontwidth($font) * strlen($text);
        $textHeight = imagefontheight($font);
        $padding = 6;

        // Position based on author type (stack in top-left corner)
        $yOffset = match ($annotation->author_type) {
            'internal' => 8,
            'crew' => 8 + $textHeight + $padding * 2 + 4,
            'customer' => 8 + ($textHeight + $padding * 2 + 4) * 2,
            default => 8,
        };

        $bgColor = imagecolorallocatealpha($image, $r, $g, $b, 40);
        $textColor = imagecolorallocate($image, 255, 255, 255);

        imagefilledrectangle(
            $image,
            8,
            $yOffset,
            8 + $textWidth + $padding * 2,
            $yOffset + $textHeight + $padding * 2,
            $bgColor
        );

        imagestring($image, $font, 8 + $padding, $yOffset + $padding, $text, $textColor);
    }

    private function drawFabricObject(\GdImage $image, array $obj, float $scaleX, float $scaleY): void
    {
        $type = strtolower($obj['type'] ?? '');
        $color = $this->parseColor($obj['stroke'] ?? $obj['fill'] ?? '#ff0000');
        $strokeWidth = (int) (($obj['strokeWidth'] ?? 2) * $scaleX);

        $gdColor = imagecolorallocatealpha($image, $color[0], $color[1], $color[2], $color[3] ?? 0);
        imagesetthickness($image, max(1, $strokeWidth));

        match ($type) {
            'path' => $this->drawPath($image, $obj, $scaleX, $scaleY, $gdColor, $strokeWidth),
            'rect' => $this->drawRect($image, $obj, $scaleX, $scaleY, $gdColor),
            'circle', 'ellipse' => $this->drawEllipse($image, $obj, $scaleX, $scaleY, $gdColor),
            'line' => $this->drawLine($image, $obj, $scaleX, $scaleY, $gdColor),
            'group' => $this->drawGroup($image, $obj, $scaleX, $scaleY),
            'i-text', 'itext', 'textbox', 'text' => $this->drawText($image, $obj, $scaleX, $scaleY),
            default => null,
        };
    }

    private function drawPath(\GdImage $image, array $obj, float $scaleX, float $scaleY, int $color, int $strokeWidth): void
    {
        $path = $obj['path'] ?? [];
        $objScaleX = ($obj['scaleX'] ?? 1) * $scaleX;
        $objScaleY = ($obj['scaleY'] ?? 1) * $scaleY;

        imagesetthickness($image, max(1, $strokeWidth));
        $prevX = null;
        $prevY = null;

        foreach ($path as $cmd) {
            $cmdType = $cmd[0] ?? '';
            if ($cmdType === 'M' && isset($cmd[1], $cmd[2])) {
                $prevX = $cmd[1] * $objScaleX;
                $prevY = $cmd[2] * $objScaleY;
            } elseif ($cmdType === 'L' && $prevX !== null && isset($cmd[1], $cmd[2])) {
                $x = $cmd[1] * $objScaleX;
                $y = $cmd[2] * $objScaleY;
                imageline($image, (int) $prevX, (int) $prevY, (int) $x, (int) $y, $color);
                $prevX = $x;
                $prevY = $y;
            } elseif ($cmdType === 'Q' && $prevX !== null && isset($cmd[1], $cmd[2], $cmd[3], $cmd[4])) {
                $cx = $cmd[1] * $objScaleX;
                $cy = $cmd[2] * $objScaleY;
                $x = $cmd[3] * $objScaleX;
                $y = $cmd[4] * $objScaleY;
                $this->drawQuadBezier($image, $prevX, $prevY, $cx, $cy, $x, $y, $color);
                $prevX = $x;
                $prevY = $y;
            }
        }
    }

    private function drawQuadBezier(\GdImage $image, float $x0, float $y0, float $cx, float $cy, float $x1, float $y1, int $color): void
    {
        $steps = 20;
        $px = $x0;
        $py = $y0;

        for ($i = 1; $i <= $steps; $i++) {
            $t = $i / $steps;
            $mt = 1 - $t;
            $nx = $mt * $mt * $x0 + 2 * $mt * $t * $cx + $t * $t * $x1;
            $ny = $mt * $mt * $y0 + 2 * $mt * $t * $cy + $t * $t * $y1;
            imageline($image, (int) $px, (int) $py, (int) $nx, (int) $ny, $color);
            $px = $nx;
            $py = $ny;
        }
    }

    private function drawRect(\GdImage $image, array $obj, float $scaleX, float $scaleY, int $color): void
    {
        $left = ($obj['left'] ?? 0) * $scaleX;
        $top = ($obj['top'] ?? 0) * $scaleY;
        $width = ($obj['width'] ?? 0) * ($obj['scaleX'] ?? 1) * $scaleX;
        $height = ($obj['height'] ?? 0) * ($obj['scaleY'] ?? 1) * $scaleY;

        imagerectangle($image, (int) $left, (int) $top, (int) ($left + $width), (int) ($top + $height), $color);
    }

    private function drawEllipse(\GdImage $image, array $obj, float $scaleX, float $scaleY, int $color): void
    {
        $left = ($obj['left'] ?? 0) * $scaleX;
        $top = ($obj['top'] ?? 0) * $scaleY;
        $rx = ($obj['radius'] ?? $obj['rx'] ?? 50) * ($obj['scaleX'] ?? 1) * $scaleX;
        $ry = ($obj['radius'] ?? $obj['ry'] ?? 50) * ($obj['scaleY'] ?? 1) * $scaleY;

        imageellipse($image, (int) ($left + $rx), (int) ($top + $ry), (int) ($rx * 2), (int) ($ry * 2), $color);
    }

    private function drawLine(\GdImage $image, array $obj, float $scaleX, float $scaleY, int $color): void
    {
        $left = ($obj['left'] ?? 0) * $scaleX;
        $top = ($obj['top'] ?? 0) * $scaleY;
        $x1 = ($obj['x1'] ?? 0) * $scaleX + $left;
        $y1 = ($obj['y1'] ?? 0) * $scaleY + $top;
        $x2 = ($obj['x2'] ?? 0) * $scaleX + $left;
        $y2 = ($obj['y2'] ?? 0) * $scaleY + $top;

        imageline($image, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $color);

        $angle = atan2($y2 - $y1, $x2 - $x1);
        $arrowLen = 15 * $scaleX;
        $arrowAngle = M_PI / 6;

        imageline($image, (int) $x2, (int) $y2, (int) ($x2 - $arrowLen * cos($angle - $arrowAngle)), (int) ($y2 - $arrowLen * sin($angle - $arrowAngle)), $color);
        imageline($image, (int) $x2, (int) $y2, (int) ($x2 - $arrowLen * cos($angle + $arrowAngle)), (int) ($y2 - $arrowLen * sin($angle + $arrowAngle)), $color);
    }

    private function drawGroup(\GdImage $image, array $obj, float $scaleX, float $scaleY): void
    {
        $objects = $obj['objects'] ?? [];
        $groupCenterX = ($obj['left'] ?? 0) * $scaleX;
        $groupCenterY = ($obj['top'] ?? 0) * $scaleY;
        $groupScaleX = ($obj['scaleX'] ?? 1);
        $groupScaleY = ($obj['scaleY'] ?? 1);

        foreach ($objects as $nested) {
            $nestedType = strtolower($nested['type'] ?? '');
            $color = $this->parseColor($nested['stroke'] ?? $nested['fill'] ?? '#ff0000');
            $strokeWidth = (int) (($nested['strokeWidth'] ?? 2) * $scaleX);
            $gdColor = imagecolorallocatealpha($image, $color[0], $color[1], $color[2], $color[3] ?? 0);
            imagesetthickness($image, max(1, $strokeWidth));

            if ($nestedType === 'line') {
                $x1 = $groupCenterX + ($nested['x1'] ?? 0) * $groupScaleX * $scaleX;
                $y1 = $groupCenterY + ($nested['y1'] ?? 0) * $groupScaleY * $scaleY;
                $x2 = $groupCenterX + ($nested['x2'] ?? 0) * $groupScaleX * $scaleX;
                $y2 = $groupCenterY + ($nested['y2'] ?? 0) * $groupScaleY * $scaleY;
                imageline($image, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $gdColor);
            }
        }
    }

    private function drawText(\GdImage $image, array $obj, float $scaleX, float $scaleY): void
    {
        $left = (int) (($obj['left'] ?? 0) * $scaleX);
        $top = (int) (($obj['top'] ?? 0) * $scaleY);
        $text = $obj['text'] ?? '';
        $fontSize = (int) (($obj['fontSize'] ?? 20) * $scaleX * ($obj['scaleX'] ?? 1));
        $fillColor = $this->parseColor($obj['fill'] ?? '#ff0000');
        $color = imagecolorallocate($image, $fillColor[0], $fillColor[1], $fillColor[2]);

        $font = max(1, min(5, (int) ($fontSize / 8)));
        imagestring($image, $font, $left, $top, $text, $color);
    }

    /** @return array{0: int, 1: int, 2: int, 3?: int} */
    private function parseColor(string $color): array
    {
        $color = ltrim($color, '#');

        if (strlen($color) === 6) {
            return [
                hexdec(substr($color, 0, 2)),
                hexdec(substr($color, 2, 2)),
                hexdec(substr($color, 4, 2)),
            ];
        }

        if (str_starts_with($color, 'rgb')) {
            preg_match_all('/[\d.]+/', $color, $matches);
            $vals = array_map('intval', $matches[0]);

            return [$vals[0] ?? 255, $vals[1] ?? 0, $vals[2] ?? 0];
        }

        return [255, 0, 0];
    }

    private function createImageFromFile(string $path, ?string $mimeType): \GdImage|false
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }
}
