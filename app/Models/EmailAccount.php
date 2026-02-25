<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailAccount extends Model
{
    use HasFactory;

    public const TYPE_PERSONAL = 'personal';

    public const TYPE_SHARED = 'shared';

    protected $fillable = [
        'user_id',
        'type',
        'address',
        'display_name',
        'signature_html',
        'is_active',
        'default_notify_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultNotifyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_notify_user_id');
    }

    /**
     * Members of a shared email account.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'email_account_user')
            ->withPivot('can_send', 'receives_notifications')
            ->withTimestamps();
    }

    public function isShared(): bool
    {
        return $this->type === self::TYPE_SHARED;
    }

    public function isPersonal(): bool
    {
        return $this->type === self::TYPE_PERSONAL;
    }

    /**
     * Scope to shared accounts only.
     */
    public function scopeShared($query)
    {
        return $query->where('type', self::TYPE_SHARED);
    }

    /**
     * Scope to personal accounts only.
     */
    public function scopePersonal($query)
    {
        return $query->where('type', self::TYPE_PERSONAL);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(EmailThread::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(EmailLabel::class);
    }

    /**
     * Get the full email address (e.g. "matt@paintingmadeeasydenver.com").
     */
    public function getFullAddressAttribute(): string
    {
        return $this->address.'@'.config('email.domain');
    }

    /**
     * Scope to only active accounts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
