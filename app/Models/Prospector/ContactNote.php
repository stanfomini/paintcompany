<?php

namespace App\Models\Prospector;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactNote extends Model
{
    use HasFactory;

    protected $table = 'prospector_contact_notes';

    protected $fillable = [
        'contact_id',
        'user_id',
        'body',
    ];

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
