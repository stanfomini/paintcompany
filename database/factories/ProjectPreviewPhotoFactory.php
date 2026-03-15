<?php

namespace Database\Factories;

use App\Models\ProjectPreview;
use App\Models\ProjectPreviewPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectPreviewPhoto>
 */
class ProjectPreviewPhotoFactory extends Factory
{
    protected $model = ProjectPreviewPhoto::class;

    public function definition(): array
    {
        return [
            'project_preview_id' => ProjectPreview::factory(),
            'original_path' => 'previews/test.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
            'sort_order' => 0,
        ];
    }
}
