<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateAiMessage extends Model
{
    protected $fillable = [
        'thread_id',
        'role',
        'content',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EstimateAiThread::class, 'thread_id');
    }
}
