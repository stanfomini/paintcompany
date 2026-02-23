<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailCrmLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_message_id',
        'linkable_type',
        'linkable_id',
        'link_type',
        'linked_by',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
