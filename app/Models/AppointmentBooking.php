<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AppointmentBooking extends Model
{
    use HasFactory;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STATUSES = [
        self::STATUS_CONFIRMED => 'Confirmed',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_NO_SHOW => 'No Show',
        self::STATUS_RESCHEDULED => 'Rescheduled',
    ];

    protected $fillable = [
        'vendor_id',
        'estimator_id',
        'deal_id',
        'customer_id',
        'schedule_id',
        'status',
        'prospect_first_name',
        'prospect_last_name',
        'prospect_email',
        'prospect_phone',
        'prospect_address',
        'prospect_city',
        'prospect_state',
        'prospect_zip',
        'prospect_description',
        'estimate_preference',
        'booked_date',
        'booked_start_time',
        'booked_end_time',
        'booked_timezone',
        'booking_token',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
        'booked_by',
        'override_note',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'booked_date' => 'date',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AppointmentBooking $booking) {
            if (empty($booking->booking_token)) {
                $booking->booking_token = (string) Str::uuid();
            }
            if (empty($booking->confirmed_at) && $booking->status === self::STATUS_CONFIRMED) {
                $booking->confirmed_at = now();
            }
        });
    }

    // ─── Relationships ───

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function estimator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'estimator_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    // ─── Scopes ───

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED)
            ->whereDate('booked_date', '>=', now()->toDateString());
    }

    public function scopeForEstimator(Builder $query, int $estimatorId): Builder
    {
        return $query->where('estimator_id', $estimatorId);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('booked_date', $date);
    }

    public function scopeNeedsReminder(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED)
            ->whereNull('reminder_sent_at')
            ->whereDate('booked_date', now()->addDay()->toDateString());
    }

    // ─── Methods ───

    public function cancel(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    public function markCompleted(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
    }

    public function markNoShow(): void
    {
        $this->update(['status' => self::STATUS_NO_SHOW]);
    }

    public function markRescheduled(): void
    {
        $this->update(['status' => self::STATUS_RESCHEDULED]);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function getProspectFullName(): string
    {
        return trim($this->prospect_first_name.' '.$this->prospect_last_name);
    }
}
