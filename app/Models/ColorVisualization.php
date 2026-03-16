<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ColorVisualization extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid', 'user_id', 'estimate_id', 'sms_conversation_id', 'session_id',
        'original_image_path', 'result_image_path', 'prompt',
        'parsed_colors', 'status', 'error_message', 'ai_provider',
        'disk', 'lead_name', 'lead_email', 'lead_phone', 'lead_address',
    ];

    protected function casts(): array
    {
        return [
            'parsed_colors' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function smsConversation(): BelongsTo
    {
        return $this->belongsTo(SmsConversation::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(string $resultImagePath, ?array $parsedColors = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'result_image_path' => $resultImagePath,
            'parsed_colors' => $parsedColors ?? $this->parsed_colors,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
