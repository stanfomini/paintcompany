<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorContractSigner extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_contract_id',
        'user_id',
        'order',
        'signed_at',
        'signature_data',
        'signature_type',
        'signer_ip',
        'signer_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'order' => 'integer',
        ];
    }

    // ─── Relationships ───

    public function contract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class, 'vendor_contract_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Helpers ───

    public function hasSigned(): bool
    {
        return $this->signed_at !== null;
    }
}
