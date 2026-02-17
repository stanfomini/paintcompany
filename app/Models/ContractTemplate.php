<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'content_type',
        'content',
        'pdf_path',
        'fields',
        'merge_tags',
        'role_name',
        'is_active',
        'version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'merge_tags' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function employeeContracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRichText(Builder $query): Builder
    {
        return $query->where('content_type', 'rich_text');
    }

    public function scopePdf(Builder $query): Builder
    {
        return $query->where('content_type', 'pdf');
    }

    /**
     * Get the standard merge tags available for contract templates.
     *
     * @return list<string>
     */
    public static function getAvailableMergeTags(): array
    {
        return [
            '{employee_name}',
            '{employee_number}',
            '{job_title}',
            '{department}',
            '{pay_rate}',
            '{pay_type}',
            '{hire_date}',
            '{company_name}',
            '{company_address}',
            '{date}',
            '{employee_address}',
            '{employee_phone}',
            '{employee_email}',
        ];
    }
}
