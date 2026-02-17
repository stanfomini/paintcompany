<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayStub extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_profile_id',
        'pay_period_id',
        'hours_regular',
        'hours_overtime',
        'hours_pto',
        'commission_amount',
        'commission_details',
        'rate_regular',
        'rate_overtime',
        'gross_pay',
        'federal_tax',
        'state_tax',
        'local_tax',
        'social_security',
        'medicare',
        'additional_medicare',
        'pretax_deductions',
        'posttax_deductions',
        'total_deductions',
        'net_pay',
        'ytd_gross',
        'ytd_federal_tax',
        'ytd_state_tax',
        'ytd_social_security',
        'ytd_medicare',
        'ytd_commission',
        'pdf_path',
        'check_number',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'hours_regular' => 'decimal:2',
            'hours_overtime' => 'decimal:2',
            'hours_pto' => 'decimal:2',
            'rate_regular' => 'decimal:2',
            'rate_overtime' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'federal_tax' => 'decimal:2',
            'state_tax' => 'decimal:2',
            'local_tax' => 'decimal:2',
            'social_security' => 'decimal:2',
            'medicare' => 'decimal:2',
            'additional_medicare' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'ytd_gross' => 'decimal:2',
            'ytd_federal_tax' => 'decimal:2',
            'ytd_state_tax' => 'decimal:2',
            'ytd_social_security' => 'decimal:2',
            'ytd_medicare' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'commission_details' => 'array',
            'ytd_commission' => 'decimal:2',
            'pretax_deductions' => 'array',
            'posttax_deductions' => 'array',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}
