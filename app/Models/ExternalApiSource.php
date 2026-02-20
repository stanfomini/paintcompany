<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ExternalApiSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'token',
        'url',
        'http_method',
        'is_active',
        'auth_type',
        'auth_header',
        'auth_value',
        'custom_headers',
        'poll_interval',
        'data_path',
        'dedup_field',
        'last_response',
        'last_response_hash',
        'processed_ids',
        'last_polled_at',
        'last_success_at',
        'poll_count',
        'error_count',
        'last_error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auth_value' => 'encrypted',
            'custom_headers' => 'array',
            'last_response' => 'array',
            'processed_ids' => 'array',
            'last_polled_at' => 'datetime',
            'last_success_at' => 'datetime',
            'poll_count' => 'integer',
            'error_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ExternalApiSource $source) {
            if (empty($source->token)) {
                $source->token = (string) Str::uuid();
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Build an HTTP request with configured auth and headers.
     */
    public function buildHttpRequest(): PendingRequest
    {
        $request = Http::timeout(30)->acceptJson();

        match ($this->auth_type) {
            'bearer' => $request->withToken($this->auth_value),
            'api_key' => $request->withHeaders([$this->auth_header => $this->auth_value]),
            default => null,
        };

        if ($this->custom_headers) {
            $request->withHeaders($this->custom_headers);
        }

        return $request;
    }

    /**
     * Check if this source is due for polling based on its interval.
     */
    public function shouldPollNow(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! $this->last_polled_at) {
            return true;
        }

        $minutes = match ($this->poll_interval) {
            'every_1_min' => 1,
            'every_5_min' => 5,
            'every_15_min' => 15,
            'every_30_min' => 30,
            'hourly' => 60,
            default => 5,
        };

        return $this->last_polled_at->addMinutes($minutes)->isPast();
    }
}
