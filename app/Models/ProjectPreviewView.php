<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPreviewView extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_preview_id',
        'user_id',
        'viewed_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(ProjectPreview::class, 'project_preview_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a view for a project preview.
     */
    public static function record(ProjectPreview $preview, ?User $user = null): self
    {
        return static::create([
            'project_preview_id' => $preview->id,
            'user_id' => $user?->id,
            'viewed_at' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
