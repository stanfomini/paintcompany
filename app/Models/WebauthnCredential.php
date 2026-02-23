<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebauthnCredential extends Model
{
    protected $primaryKey = 'credential_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'credential_id',
        'user_id',
        'public_key',
        'counter',
        'device_name',
        'transports',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'last_used_at' => 'datetime',
            'counter' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
