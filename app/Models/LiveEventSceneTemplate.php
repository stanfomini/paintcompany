<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveEventSceneTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'base_layout',
        'scene_data',
    ];

    protected function casts(): array
    {
        return [
            'scene_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Default layout presets — slot geometry only, no overlays.
     *
     * @return array<int, array{name: string, slots: array, overlays: array}>
     */
    public static function defaultLayouts(): array
    {
        return [
            [
                'name' => 'Solo',
                'slots' => [
                    ['source' => 'host', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'label' => 'A'],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Interview',
                'slots' => [
                    ['source' => 'host', 'x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 1, 'label' => 'A'],
                    ['source' => 'guest-1', 'x' => 0.5, 'y' => 0, 'w' => 0.5, 'h' => 1, 'label' => 'B'],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Guest Spotlight',
                'slots' => [
                    ['source' => 'guest-1', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'label' => 'A'],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Screen Share',
                'slots' => [
                    ['source' => 'screen', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'label' => 'A'],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Picture-in-Picture',
                'slots' => [
                    ['source' => 'guest-1', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'label' => 'A'],
                    ['source' => 'host', 'x' => 0.73, 'y' => 0.70, 'w' => 0.25, 'h' => 0.25, 'label' => 'B'],
                ],
                'overlays' => [],
            ],
        ];
    }

    /**
     * Backward-compatible alias.
     */
    public static function defaultScenes(): array
    {
        return static::defaultLayouts();
    }
}
