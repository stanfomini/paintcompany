<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallGroupMember extends Model
{
    protected $fillable = [
        'call_group_id',
        'user_id',
        'forwarding_number',
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

    public function callGroup(): BelongsTo
    {
        return $this->belongsTo(CallGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the forwarding number: member override or user's forwarding_number.
     */
    public function getForwardingNumber(): ?string
    {
        return $this->forwarding_number ?? $this->user?->forwarding_number;
    }
}
