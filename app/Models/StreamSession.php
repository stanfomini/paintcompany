<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StreamSession extends Model
{
    /** @use HasFactory<\Database\Factories\StreamSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'room_id',
        'title',
        'status',
        'scenes',
        'current_scene_index',
        'destinations',
        'overlays',
        'recording_path',
        'recording_disk',
        'started_at',
        'ended_at',
        'duration_seconds',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scenes' => 'array',
            'destinations' => 'array',
            'overlays' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPreparing(): bool
    {
        return $this->status === 'preparing';
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function isEnded(): bool
    {
        return $this->status === 'ended';
    }

    /**
     * Generate a signed guest invite URL (24-hour expiry).
     */
    public function generateGuestLink(): string
    {
        return URL::temporarySignedRoute(
            'studio.join',
            now()->addHours(24),
            ['token' => $this->room_id]
        );
    }

    /**
     * Get the default scene presets.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaultScenes(): array
    {
        return [
            [
                'name' => 'Solo',
                'layout' => 'solo',
                'slots' => [
                    ['source' => 'local', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Split Screen',
                'layout' => 'split',
                'slots' => [
                    ['source' => 'local', 'x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 1],
                    ['source' => 'remote-1', 'x' => 0.5, 'y' => 0, 'w' => 0.5, 'h' => 1],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Picture-in-Picture',
                'layout' => 'pip',
                'slots' => [
                    ['source' => 'local', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
                    ['source' => 'remote-1', 'x' => 0.75, 'y' => 0.72, 'w' => 0.23, 'h' => 0.23],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Walkaround',
                'layout' => 'walkaround',
                'slots' => [
                    ['source' => 'local', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
                ],
                'overlays' => [
                    ['type' => 'image', 'source' => 'company_logo', 'x' => 0.02, 'y' => 0.02, 'w' => 0.15, 'h' => 0.1, 'visible' => true],
                ],
            ],
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StreamSession $session) {
            if (empty($session->room_id)) {
                $session->room_id = 'studio-'.Str::random(12);
            }
            if (empty($session->scenes)) {
                $session->scenes = self::defaultScenes();
            }
        });
    }
}
