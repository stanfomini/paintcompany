<?php

namespace App\Models\Prospector;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Sequence extends Model
{
    use HasFactory;

    protected $table = 'prospector_sequences';

    protected $fillable = [
        'ulid',
        'company_id',
        'name',
        'description',
        'status',
        'trigger_type',
        'trigger_config',
        'exit_conditions',
        'business_hours_only',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'exit_conditions' => 'array',
            'business_hours_only' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Sequence $model) {
            if (empty($model->ulid)) {
                $model->ulid = Str::ulid()->toBase32();
            }
        });
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class, 'sequence_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class, 'sequence_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if this sequence is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the count of currently active enrollments.
     */
    public function getActiveEnrollmentCount(): int
    {
        return $this->enrollments()->where('status', 'active')->count();
    }

    /**
     * Get the completion rate as a percentage.
     */
    public function getCompletionRate(): float
    {
        $total = $this->enrollments()->count();

        if ($total === 0) {
            return 0.0;
        }

        $completed = $this->enrollments()->where('status', 'completed')->count();

        return round(($completed / $total) * 100, 2);
    }
}
