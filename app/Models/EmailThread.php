<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject',
        'original_message_id',
        'email_account_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(EmailMessage::class)->latestOfMany('sent_at');
    }

    /**
     * Scope to threads for a specific account.
     */
    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('email_account_id', $accountId);
    }

    /**
     * Scope to order by latest activity.
     */
    public function scopeLatestActivity($query)
    {
        return $query->orderByDesc('last_message_at');
    }

    /**
     * Scope to threads that have messages with a given label.
     */
    public function scopeWithLabel($query, string $labelSlug)
    {
        return $query->whereHas('messages.labels', function ($q) use ($labelSlug) {
            $q->where('slug', $labelSlug);
        });
    }
}
