<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookFieldMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'webhook_endpoint_id',
        'source_path',
        'target_model',
        'target_field',
        'transform',
        'default_value',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function webhookEndpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class);
    }

    /**
     * Apply the configured transform to a value.
     */
    public function applyTransform(mixed $value): mixed
    {
        if ($this->transform === null) {
            return $value !== null && $value !== '' ? $value : $this->default_value;
        }

        return match ($this->transform) {
            'split_first_name' => $this->splitFirstName($value),
            'split_last_name' => $this->splitLastName($value),
            'format_phone' => $this->formatPhone($value),
            'join_details' => $this->joinDetails($value),
            default => $value !== null && $value !== '' ? $value : $this->default_value,
        };
    }

    protected function splitFirstName(mixed $value): string
    {
        if (empty($value)) {
            return $this->default_value ?? '';
        }

        $parts = explode(' ', trim((string) $value), 2);

        return $parts[0];
    }

    protected function splitLastName(mixed $value): string
    {
        if (empty($value)) {
            return $this->default_value ?? '';
        }

        $parts = explode(' ', trim((string) $value), 2);

        return $parts[1] ?? '';
    }

    protected function formatPhone(mixed $value): ?string
    {
        if (empty($value)) {
            return $this->default_value;
        }

        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    protected function joinDetails(mixed $value): ?string
    {
        if (empty($value)) {
            return $this->default_value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (is_array($value)) {
            return implode(', ', array_filter($value));
        }

        return (string) $value;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
