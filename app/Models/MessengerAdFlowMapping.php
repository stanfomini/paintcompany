<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessengerAdFlowMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'facebook_ad_id',
        'flow_id',
        'facebook_ad_name',
        'facebook_campaign_name',
        'facebook_adset_name',
        'facebook_ad_status',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(MessengerBotFlow::class, 'flow_id');
    }
}
