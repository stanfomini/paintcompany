<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RvmDrop extends Model
{
    use HasFactory;

    protected $table = 'prospector_rvm_drops';

    protected $fillable = [
        'company_id',
        'contact_id',
        'template_id',
        'sequence_enrollment_id',
        'method',
        'provider_id',
        'status',
        'from_number',
        'to_number',
        'error',
        'sent_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RvmTemplate::class, 'template_id');
    }

    public function sequenceEnrollment(): BelongsTo
    {
        return $this->belongsTo(SequenceEnrollment::class, 'sequence_enrollment_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', 'queued');
    }

    public function scopeDelivered(Builder $query): void
    {
        $query->where('status', 'delivered');
    }

    public function scopeFailed(Builder $query): void
    {
        $query->where('status', 'failed');
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    public function markSent(string $providerId): void
    {
        $this->update([
            'status' => 'sent',
            'provider_id' => $providerId,
            'sent_at' => now(),
        ]);
    }

    public function markDelivered(): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }

    public function markFailed(?string $error = null): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
        ]);
    }
}
