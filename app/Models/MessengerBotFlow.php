<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessengerBotFlow extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'greeting_message',
        'completion_action',
        'timeout_minutes',
        'business_hours_only',
    ];

    protected function casts(): array
    {
        return [
            'business_hours_only' => 'boolean',
            'timeout_minutes' => 'integer',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(MessengerBotStep::class, 'flow_id')->orderBy('sort_order');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(MessengerConversation::class, 'flow_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
