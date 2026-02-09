<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateAmendment extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateAmendmentFactory> */
    use HasFactory;

    protected $fillable = [
        'estimate_id',
        'amended_by',
        'amendment_number',
        'changes',
        'previous_total',
        'new_total',
        'customer_acknowledged_at',
        'customer_initials',
        'customer_ip_address',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'previous_total' => 'decimal:2',
            'new_total' => 'decimal:2',
            'customer_acknowledged_at' => 'datetime',
        ];
    }

    /**
     * Get the estimate this amendment belongs to.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the user who made this amendment.
     */
    public function amendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }

    /**
     * Check if this amendment has been acknowledged by the customer.
     */
    public function isAcknowledged(): bool
    {
        return $this->customer_acknowledged_at !== null;
    }

    /**
     * Get the total change amount (new - previous).
     */
    public function getTotalChangeAmount(): float
    {
        return (float) $this->new_total - (float) $this->previous_total;
    }

    /**
     * Get the total change formatted as a string with +/- prefix.
     */
    public function getTotalChangeFormatted(): string
    {
        $change = $this->getTotalChangeAmount();
        $prefix = $change >= 0 ? '+' : '-';

        return $prefix.'$'.number_format(abs($change), 2);
    }
}
