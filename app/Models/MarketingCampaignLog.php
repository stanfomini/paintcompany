<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'marketing_strategy_id',
        'trigger_type',
        'trigger_id',
        'trigger_model',
        'action',
        'recipient_phone',
        'recipient_address',
        'message_body',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(MarketingStrategy::class, 'marketing_strategy_id');
    }
}
