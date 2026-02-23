<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'address',
        'display_name',
        'signature_html',
        'is_active',
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
