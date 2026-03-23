<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingStrategy extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'category',
        'name',
        'status',
        'configuration',
        'notes',
        'activated_at',
        'paused_at',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function campaignLogs(): HasMany
    {
        return $this->hasMany(MarketingCampaignLog::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function activate(): void
    {
        $this->update([
            'status' => 'active',
            'activated_at' => now(),
            'paused_at' => null,
        ]);
    }

    public function pause(): void
    {
        $this->update([
            'status' => 'paused',
            'paused_at' => now(),
        ]);
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->configuration, $key, $default);
    }

    public function setConfig(string $key, mixed $value): void
    {
        $config = $this->configuration ?? [];
        data_set($config, $key, $value);
        $this->update(['configuration' => $config]);
    }
}
