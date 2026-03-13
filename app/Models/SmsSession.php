<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsSession extends Model
{
    protected $fillable = [
        'user_id',
        'customer_id',
        'thread_id',
        'last_message_id',
        'phone_number',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    /**
     * Find an active session by phone number.
     */
    public static function findActiveByPhone(string $phone): ?self
    {
        return static::where('phone_number', $phone)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }
}
