<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'email_thread_id',
        'email_account_id',
        'direction',
        'from_address',
        'from_name',
        'to_addresses',
        'cc_addresses',
        'bcc_addresses',
        'subject',
        'body_html',
        'body_text',
        'snippet',
        'message_id',
        'in_reply_to',
        'references',
        'mailgun_message_id',
        'raw_headers',
        'is_read',
        'is_starred',
        'sent_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'to_addresses' => 'array',
            'cc_addresses' => 'array',
            'bcc_addresses' => 'array',
            'references' => 'array',
            'raw_headers' => 'array',
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'email_thread_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(EmailLabel::class, 'email_message_label')
            ->withTimestamps();
    }

    public function crmLinks(): HasMany
    {
        return $this->hasMany(EmailCrmLink::class);
    }

    /**
     * Mark the message as read.
     */
    public function markAsRead(): void
    {
        if (! $this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Toggle the starred status.
     */
    public function toggleStar(): void
    {
        $this->update(['is_starred' => ! $this->is_starred]);
    }

    /**
     * Scope to inbound messages only.
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    /**
     * Scope to outbound messages only.
     */
    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * Scope to unread messages only.
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope to starred messages only.
     */
    public function scopeStarred($query)
    {
        return $query->where('is_starred', true);
    }

    /**
     * Scope for fulltext search (MySQL) or LIKE fallback (SQLite).
     */
    public function scopeSearch($query, string $term)
    {
        if ($query->getConnection()->getDriverName() === 'mysql') {
            return $query->whereFullText(['subject', 'body_text'], $term);
        }

        return $query->where(function ($q) use ($term) {
            $q->where('subject', 'like', "%{$term}%")
                ->orWhere('body_text', 'like', "%{$term}%");
        });
    }

    /**
     * Scope to messages with a given label.
     */
    public function scopeWithLabel($query, string $labelSlug)
    {
        return $query->whereHas('labels', function ($q) use ($labelSlug) {
            $q->where('slug', $labelSlug);
        });
    }
}
