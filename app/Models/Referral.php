<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use HasFactory;

    public const STATUS_LEAD = 'lead';

    public const STATUS_QUOTED = 'quoted';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_DEPOSIT_PAID = 'deposit_paid';

    public const STATUS_REWARD_SENT = 'reward_sent';

    public const STATUSES = [
        'lead' => 'Lead',
        'quoted' => 'Quoted',
        'signed' => 'Signed',
        'deposit_paid' => 'Deposit Paid',
        'reward_sent' => 'Reward Sent',
    ];

    // Order matters - used for advancement validation
    public const STATUS_ORDER = [
        self::STATUS_LEAD,
        self::STATUS_QUOTED,
        self::STATUS_SIGNED,
        self::STATUS_DEPOSIT_PAID,
        self::STATUS_REWARD_SENT,
    ];

    protected $fillable = [
        'referrer_id',
        'customer_id',
        'deal_id',
        'status',
        'reward_amount',
        'reward_sent_at',
        'reward_notes',
    ];

    protected function casts(): array
    {
        return [
            'reward_amount' => 'decimal:2',
            'reward_sent_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * Advance the referral to a new status.
     * Only allows forward progression.
     */
    public function advanceTo(string $status): bool
    {
        $currentIndex = array_search($this->status, self::STATUS_ORDER);
        $newIndex = array_search($status, self::STATUS_ORDER);

        if ($newIndex === false || $newIndex <= $currentIndex) {
            return false;
        }

        $this->status = $status;

        return $this->save();
    }

    public function isRewardEarned(): bool
    {
        return in_array($this->status, [self::STATUS_DEPOSIT_PAID, self::STATUS_REWARD_SENT]);
    }

    public function isRewardSent(): bool
    {
        return $this->status === self::STATUS_REWARD_SENT;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
