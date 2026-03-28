<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveEventSceneTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\LiveEventSceneTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'scenes',
        'overlays',
    ];

    protected function casts(): array
    {
        return [
            'scenes' => 'array',
            'overlays' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the default scene presets for new events.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaultScenes(): array
    {
        return [
            [
                'name' => 'Solo',
                'slots' => [
                    ['source' => 'host', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Interview',
                'slots' => [
                    ['source' => 'host', 'x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 1],
                    ['source' => 'guest-1', 'x' => 0.5, 'y' => 0, 'w' => 0.5, 'h' => 1],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Guest Spotlight',
                'slots' => [
                    ['source' => 'guest-1', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Screen Share',
                'slots' => [
                    ['source' => 'screen', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Picture-in-Picture',
                'slots' => [
                    ['source' => 'guest-1', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
                    ['source' => 'host', 'x' => 0.73, 'y' => 0.7, 'w' => 0.25, 'h' => 0.25],
                ],
                'overlays' => [],
            ],
        ];
    }
}
