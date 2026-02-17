<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class JobListing extends Model
{
    /** @use HasFactory<\Database\Factories\JobListingFactory> */
    use HasFactory;

    public const EMPLOYMENT_TYPES = [
        'full_time' => 'Full Time',
        'part_time' => 'Part Time',
        'seasonal' => 'Seasonal',
        'temporary' => 'Temporary',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'active' => 'Active',
        'closed' => 'Closed',
    ];

    protected $fillable = [
        'title',
        'description',
        'requirements',
        'location',
        'pay_range_min',
        'pay_range_max',
        'employment_type',
        'status',
        'qr_code_token',
        'positions_available',
        'positions_filled',
        'published_at',
        'closed_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pay_range_min' => 'decimal:2',
            'pay_range_max' => 'decimal:2',
            'positions_available' => 'integer',
            'positions_filled' => 'integer',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (JobListing $listing) {
            if (empty($listing->qr_code_token)) {
                $listing->qr_code_token = (string) Str::uuid();
            }
        });
    }

    /**
     * @return HasMany<Applicant, $this>
     */
    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<JobListing>  $query
     * @return Builder<JobListing>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * @param  Builder<JobListing>  $query
     * @return Builder<JobListing>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function remainingPositions(): int
    {
        return max(0, $this->positions_available - $this->positions_filled);
    }

    public function getPayRangeDisplay(): string
    {
        if ($this->pay_range_min && $this->pay_range_max) {
            return '$'.number_format((float) $this->pay_range_min).' - $'.number_format((float) $this->pay_range_max);
        }

        if ($this->pay_range_min) {
            return 'From $'.number_format((float) $this->pay_range_min);
        }

        if ($this->pay_range_max) {
            return 'Up to $'.number_format((float) $this->pay_range_max);
        }

        return 'Competitive';
    }
}
