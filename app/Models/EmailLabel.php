<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EmailLabel extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'name',
        'slug',
        'color',
        'is_system',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(EmailMessage::class, 'email_message_label')
            ->withTimestamps();
    }

    /**
     * Scope to system labels only.
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }
}
