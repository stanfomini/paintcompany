<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLogUser extends Model
{
    protected $table = 'call_log_user';

    protected $fillable = [
        'call_log_id',
        'user_id',
        'listened_at',
        'handled_at',
        'handled_action',
    ];

    protected function casts(): array
    {
        return [
            'listened_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
