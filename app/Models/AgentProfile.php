<?php

namespace App\Models;

use App\Traits\HasCommissionRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfile extends Model
{
    use HasCommissionRules;

    protected $fillable = [
        'user_id',
        'company_name',
        'license_number',
        'bio',
        'phone',
        'commission_rate',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFormattedCommissionRateAttribute(): string
    {
        return $this->commission_rate.'%';
    }

    public function calculateCommission(float $dealValue): float
    {
        return $dealValue * ($this->commission_rate / 100);
    }
}
