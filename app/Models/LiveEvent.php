<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveEvent extends Model
{
    /** @use HasFactory<\Database\Factories\LiveEventFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'service_area_id',
        'service_id',
        'host_user_id',
        'scheduled_at',
        'started_at',
        'ended_at',
        'status',
        'access_type',
        'max_viewers',
        'enable_recording',
        'livekit_room_name',
        'recording_path',
        'cover_image',
        'chat_thread_id',
        'reminder_sent_at',
        'scenes',
        'active_scene_index',
        'use_frame_mode',
        'backstage_chat_thread_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'enable_recording' => 'boolean',
            'reminder_sent_at' => 'datetime',
            'scenes' => 'array',
            'use_frame_mode' => 'boolean',
        ];
    }

    /**
     * Use slug for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the service area for this event.
     */
    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /**
     * Get the service for this event.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the host user for this event.
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /**
     * Get the chat thread for this event.
     */
    public function chatThread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class);
    }

    /**
     * Get the resources for this event.
     */
    public function resources(): HasMany
    {
        return $this->hasMany(LiveEventResource::class);
    }

    /**
     * Get the questions for this event.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(LiveEventQuestion::class);
    }

    /**
     * Get the registrations for this event.
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(LiveEventRegistration::class);
    }

    /**
     * Get the guest invitations for this event.
     */
    public function guests(): HasMany
    {
        return $this->hasMany(LiveEventGuest::class);
    }

    /**
     * Get the backstage chat thread for producer-guest communication.
     */
    public function backstageChatThread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'backstage_chat_thread_id');
    }

    /**
     * Get the active scene configuration.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveScene(): ?array
    {
        $scenes = $this->scenes ?? [];

        return $scenes[$this->active_scene_index] ?? $scenes[0] ?? null;
    }

    /**
     * Get scenes or return default presets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getScenesOrDefaults(): array
    {
        return $this->scenes ?? LiveEventSceneTemplate::defaultScenes();
    }

    /**
     * Scope to filter upcoming scheduled events.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '>', now());
    }

    /**
     * Scope to filter live events.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'live');
    }

    /**
     * Scope to filter ended events.
     */
    public function scopeEnded(Builder $query): Builder
    {
        return $query->where('status', 'ended');
    }

    /**
     * Scope to filter events by service area.
     */
    public function scopeForArea(Builder $query, int $areaId): Builder
    {
        return $query->where('service_area_id', $areaId);
    }

    /**
     * Scope to filter events by service.
     */
    public function scopeForService(Builder $query, int $serviceId): Builder
    {
        return $query->where('service_id', $serviceId);
    }
}
