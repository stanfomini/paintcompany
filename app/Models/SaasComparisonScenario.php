<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaasComparisonScenario extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'created_by',
        'settings',
        'public_token',
        'public_token_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'public_token_expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
        return $query->where('public_token', $token);
    }
}
