<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageVideoSectionItem extends Model
{
    protected $fillable = ['landing_page_video_section_id', 'video_recording_id', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LandingPageVideoSection::class, 'landing_page_video_section_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class, 'video_recording_id');
    }
}
