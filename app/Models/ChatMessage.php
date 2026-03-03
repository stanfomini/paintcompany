<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_TEXT = 'text';

    public const TYPE_FILE = 'file';

    public const TYPE_IMAGE = 'image';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_AUDIO = 'audio';

    protected $fillable = [
        'thread_id',
        'user_id',
        'parent_id',
        'content',
        'type',
        'metadata',
        'is_internal',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the thread this message belongs to.
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    /**
     * Get the user who sent this message.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent message (for replies).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'parent_id');
    }

    /**
     * Get replies to this message.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'parent_id');
    }

    /**
     * Get the attachments for this message.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }

    /**
     * Get the read receipts for this message.
     */
    public function reads(): HasMany
    {
        return $this->hasMany(ChatMessageRead::class, 'message_id');
    }

    /**
     * Check if message has been read by a user.
     */
    public function isReadBy(User $user): bool
    {
        return $this->reads()->where('user_id', $user->id)->exists();
    }

    /**
     * Mark this message as read by a user.
     */
    public function markAsReadBy(User $user): ChatMessageRead
    {
        return $this->reads()->firstOrCreate(
            ['user_id' => $user->id],
            ['read_at' => now()]
        );
    }

    /**
     * Check if message has been edited.
     */
    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /**
     * Mark message as edited.
     */
    public function markAsEdited(): bool
    {
        return $this->update(['edited_at' => now()]);
    }

    /**
     * Check if message is a reply.
     */
    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Check if message has attachments.
     */
    public function hasAttachments(): bool
    {
        return $this->attachments()->exists();
    }

    /**
     * Check if this is a system message.
     */
    public function isSystem(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    /**
     * Check if this is an audio message.
     */
    public function isAudio(): bool
    {
        return $this->type === self::TYPE_AUDIO;
    }

    /**
     * Get a fresh audio URL for this message.
     * Uses a dedicated streaming route with signed URL for proper audio headers.
     */
    public function getAudioUrl(): ?string
    {
        if (! $this->isAudio()) {
            return null;
        }

        $path = $this->metadata['audio_path'] ?? null;

        if ($path) {
            $filename = basename($path);

            return \Illuminate\Support\Facades\URL::signedRoute(
                'chat.audio.stream',
                ['filename' => $filename],
                now()->addHours(24)
            );
        }

        // Fallback to stored URL (may be expired but better than nothing)
        return $this->metadata['audio_url'] ?? null;
    }

    /**
     * Scope to filter messages visible to a user.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isInternal()) {
            return $query;
        }

        return $query->where('is_internal', false);
    }

    /**
     * Scope to filter non-internal messages only.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /**
     * Scope to filter internal messages only.
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('is_internal', true);
    }

    /**
     * Scope to filter by message type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter non-system messages.
     */
    public function scopeNotSystem(Builder $query): Builder
    {
        return $query->where('type', '!=', self::TYPE_SYSTEM);
    }

    /**
     * Get the auto-detected language of the message content.
     */
    public function getDetectedLanguage(): ?string
    {
        return $this->metadata['detected_lang'] ?? null;
    }

    /**
     * Check if a translation exists for the given locale.
     */
    public function hasTranslation(string $locale): bool
    {
        return isset($this->metadata['translations'][$locale]);
    }

    /**
     * Get the translation for the given locale.
     */
    public function getTranslation(string $locale): ?string
    {
        return $this->metadata['translations'][$locale] ?? null;
    }

    /**
     * Check if this message needs a translation toggle for the viewer.
     */
    public function needsTranslationFor(string $viewerLocale): bool
    {
        $detectedLang = $this->getDetectedLanguage();

        if (! $detectedLang) {
            return false;
        }

        if ($detectedLang === $viewerLocale) {
            return false;
        }

        return $this->hasTranslation($viewerLocale);
    }
}
