<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeEquipment extends Model
{
    use HasFactory;

    protected $table = 'employee_equipment';

    protected $fillable = [
        'employee_profile_id',
        'name',
        'description',
        'serial_number',
        'assigned_at',
        'returned_at',
        'condition_on_assign',
        'condition_on_return',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'date',
            'returned_at' => 'date',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }
}
