<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class EmployeeContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_profile_id',
        'contract_template_id',
        'title',
        'content',
        'pdf_path',
        'field_values',
        'status',
        'sent_at',
        'viewed_at',
        'signed_at',
        'expires_at',
        'signature_data',
        'signature_type',
        'signer_name',
        'signer_ip',
        'signer_user_agent',
        'countersigned_by',
        'countersigned_at',
        'countersign_data',
        'token',
    ];

    protected function casts(): array
    {
        return [
            'field_values' => 'array',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'expires_at' => 'datetime',
            'countersigned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $contract) {
            if (! $contract->token) {
                $contract->token = (string) Str::uuid();
            }
        });

        static::updated(function (self $contract) {
            if ($contract->wasChanged('status') && $contract->status === 'signed') {
                $progress = EmployeeOnboardingProgress::where('employee_contract_id', $contract->id)->first();
                if ($progress && $progress->status !== 'completed') {
                    $progress->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                    ]);
                }
            }
        });
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }

    public function countersignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'countersigned_by');
    }

    public function onboardingProgress(): HasOne
    {
        return $this->hasOne(EmployeeOnboardingProgress::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['draft', 'sent', 'viewed']);
    }

    public function scopeSigned(Builder $query): Builder
    {
        return $query->where('status', 'signed');
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function isExpired(): bool
    {
        if (! $this->expires_at) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    public function markViewed(): void
    {
        if (! $this->viewed_at) {
            $this->update([
                'status' => 'viewed',
                'viewed_at' => now(),
            ]);
        }
    }

    /**
     * @param  array{signature_data: string, signature_type: string, signer_name: string, ip: string, user_agent: string}  $data
     */
    public function markSigned(array $data): void
    {
        $this->update([
            'status' => 'signed',
            'signed_at' => now(),
            'signature_data' => $data['signature_data'],
            'signature_type' => $data['signature_type'],
            'signer_name' => $data['signer_name'],
            'signer_ip' => $data['ip'],
            'signer_user_agent' => $data['user_agent'],
        ]);
    }
}
