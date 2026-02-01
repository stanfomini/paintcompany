<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalUser extends Model
{
    protected $fillable = [
        'user_id',
        'department',
        'job_title',
        'can_access_crm',
        'signature_data',
        'signature_type',
        'signature_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'can_access_crm' => 'boolean',
            'signature_updated_at' => 'datetime',
        ];
    }

    /**
     * Check if the internal user has a signature configured.
     */
    public function hasSignature(): bool
    {
        return ! empty($this->signature_data) && ! empty($this->signature_type);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
