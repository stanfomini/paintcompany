<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RoiCalculation extends Model
{
    use HasFactory;

    protected $fillable = [
        'deal_id',
        'agent_id',
        'property_value',
        'estimated_sqft',
        'bedroom_count',
        'scope',
        'intent',
        'estimated_cost_low',
        'estimated_cost_high',
        'estimated_value_increase_low',
        'estimated_value_increase_high',
        'roi_percent_low',
        'roi_percent_high',
        'calculation_inputs',
        'public_token',
        'public_token_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'property_value' => 'decimal:2',
            'estimated_sqft' => 'integer',
            'bedroom_count' => 'integer',
            'estimated_cost_low' => 'decimal:2',
            'estimated_cost_high' => 'decimal:2',
            'estimated_value_increase_low' => 'decimal:2',
            'estimated_value_increase_high' => 'decimal:2',
            'roi_percent_low' => 'decimal:2',
            'roi_percent_high' => 'decimal:2',
            'calculation_inputs' => 'array',
            'public_token_expires_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Generate a public sharing token.
     */
    public function generatePublicToken(int $days = 30): string
    {
        $this->public_token = Str::random(64);
        $this->public_token_expires_at = now()->addDays($days);
        $this->save();

        return $this->public_token;
    }

    /**
     * Check if the public token is valid.
     */
    public function isPublicTokenValid(): bool
    {
        return $this->public_token
            && $this->public_token_expires_at
            && $this->public_token_expires_at->isFuture();
    }

    /**
     * Scope to find by public token.
     */
    public function scopeByPublicToken($query, string $token)
    {
        return $query->where('public_token', $token)
            ->where('public_token_expires_at', '>', now());
    }
}
