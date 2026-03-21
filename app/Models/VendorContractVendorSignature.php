<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorContractVendorSignature extends Model
{
    protected $fillable = [
        'vendor_contract_id',
        'signer_name',
        'signer_email',
        'signer_title',
        'signer_phone',
        'signature_data',
        'signature_type',
        'signer_ip',
        'signer_user_agent',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function contract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class, 'vendor_contract_id');
    }
}
