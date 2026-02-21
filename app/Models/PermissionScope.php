<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermissionScope extends Model
{
    protected $fillable = [
        'user_id',
        'permission',
        'scope_type',
        'scope_value',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_value' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isGrant(): bool
    {
        return $this->scope_type === 'grant';
    }

    public function isRevoke(): bool
    {
        return $this->scope_type === 'revoke';
    }
}
