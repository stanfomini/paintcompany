<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatThread extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_DEAL = 'deal';

    public const TYPE_DIRECT = 'direct';

    public const TYPE_SUPPORT = 'support';

    public const SOURCE_WEBSITE_LEAD = 'website_lead';

    public const SOURCE_SETTER_SUPPORT = 'setter_support';

    public const SOURCE_SMS_INBOUND = 'sms_inbound';

    public const CHANNEL_INTERNAL = 'internal';

    public const CHANNEL_FACEBOOK = 'facebook';

    protected $fillable = [
        'deal_id',
        'type',
        'source',
        'channel',
        'customer_id',
        'external_id',
        'external_contact_id',
        'subject',
        'is_archived',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'last_message_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the deal associated with this thread.
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * Get the customer associated with this thread.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the messenger conversation for this thread.
     */
    public function messengerConversation(): HasOne
    {
        return $this->hasOne(\App\Models\MessengerConversation::class, 'thread_id');
    }

    /**
     * Get all participants in this thread.
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'thread_id');
    }

    /**
     * Get active participants (not left) in this thread.
     */
    public function activeParticipants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'thread_id')
            ->whereNull('left_at');
    }

    /**
     * Get the users participating in this thread.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants', 'thread_id', 'user_id')
            ->withPivot(['last_read_at', 'is_muted', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * Get all messages in this thread.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'thread_id');
    }

    /**
     * Get the latest message in this thread.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class, 'thread_id')->latestOfMany();
    }

    /**
     * Check if a user is a participant in this thread.
     */
    public function hasParticipant(User $user): bool
    {
        return $this->participants()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();
    }

    /**
     * Get the participant record for a user.
     */
    public function getParticipant(User $user): ?ChatParticipant
    {
        return $this->participants()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();
    }

    /**
     * Add a participant to the thread.
     */
    public function addParticipant(User $user): ChatParticipant
    {
        return $this->participants()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'joined_at' => now(),
                'left_at' => null,
            ]
        );
    }

    /**
     * Remove a participant from the thread.
     */
    public function removeParticipant(User $user): bool
    {
        return $this->participants()
            ->where('user_id', $user->id)
            ->update(['left_at' => now()]) > 0;
    }

    /**
     * Get unread message count for a user.
     */
    public function getUnreadCountFor(User $user): int
    {
        $participant = $this->getParticipant($user);

        if (! $participant) {
            return 0;
        }

        $query = $this->messages()
            ->where(function ($q) use ($user) {
                $q->where('user_id', '!=', $user->id)
                    ->orWhereNull('user_id');
            });

        // Non-internal users can't see internal messages
        if (! $user->isInternal()) {
            $query->where('is_internal', false);
        }

        if ($participant->last_read_at) {
            $query->where('created_at', '>', $participant->last_read_at);
        }

        return $query->count();
    }

    /**
     * Update the last_message_at timestamp.
     */
    public function touchLastMessage(): bool
    {
        return $this->update(['last_message_at' => now()]);
    }

    /**
     * Scope to filter threads for a specific user (active participant, not archived by them).
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('participants', function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->whereNull('left_at');
        });
    }

    /**
     * Scope to exclude threads archived by the given user.
     */
    public function scopeNotArchivedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('participants', function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->whereNull('left_at')
                ->whereNull('archived_at');
        });
    }

    /**
     * Scope to only include threads archived by the given user.
     */
    public function scopeArchivedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('participants', function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->whereNull('left_at')
                ->whereNotNull('archived_at');
        });
    }

    /**
     * Scope to filter threads based on user's view permissions.
     * Internal users with roles are filtered by chat.view_deal_chats,
     * chat.view_website_leads, and chat.view_setter_support permissions.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isInternal() && $user->roles->isNotEmpty()) {
            $canViewDeals = $user->can('chat.view_deal_chats');
            $canViewWebsiteLeads = $user->can('chat.view_website_leads');
            $canViewSetterSupport = $user->can('chat.view_setter_support');

            // Only apply filtering if user is missing at least one permission
            if (! $canViewDeals || ! $canViewWebsiteLeads || ! $canViewSetterSupport) {
                $query->where(function (Builder $q) use ($canViewDeals, $canViewWebsiteLeads, $canViewSetterSupport) {
                    if (! $canViewDeals) {
                        $q->where('type', '!=', self::TYPE_DEAL);
                    }

                    if (! $canViewWebsiteLeads && ! $canViewSetterSupport) {
                        // Block all support threads
                        $q->where('type', '!=', self::TYPE_SUPPORT);
                    } elseif (! $canViewWebsiteLeads) {
                        // Block website lead support threads only
                        $q->where(function ($sq) {
                            $sq->where('type', '!=', self::TYPE_SUPPORT)
                                ->orWhereNot('source', self::SOURCE_WEBSITE_LEAD);
                        });
                    } elseif (! $canViewSetterSupport) {
                        // Block setter support threads only
                        $q->where(function ($sq) {
                            $sq->where('type', '!=', self::TYPE_SUPPORT)
                                ->orWhereNot('source', self::SOURCE_SETTER_SUPPORT);
                        });
                    }
                });
            }
        }

        return $query;
    }

    /**
     * Scope to filter active (not archived) threads.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Scope to filter archived threads.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    /**
     * Scope to filter by thread type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter by channel.
     */
    public function scopeOfChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope to order by most recent message.
     */
    public function scopeLatestActivity(Builder $query): Builder
    {
        return $query->orderByDesc('last_message_at');
    }

    /**
     * Get a display name for this thread.
     */
    public function getDisplayNameFor(User $user): string
    {
        if ($this->subject) {
            return $this->subject;
        }

        if ($this->deal) {
            return $this->deal->title;
        }

        // For direct messages, show the other participant's name
        $otherParticipants = $this->activeParticipants()
            ->where('user_id', '!=', $user->id)
            ->with('user')
            ->get()
            ->pluck('user.name')
            ->filter();

        if ($otherParticipants->isEmpty()) {
            return 'Chat';
        }

        return $otherParticipants->implode(', ');
    }
}
