<?php

namespace App\Models\Prospector;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RvmTemplate extends Model
{
    use HasFactory;

    protected $table = 'prospector_rvm_templates';

    protected $fillable = [
        'company_id',
        'name',
        'audio_path',
        'duration_seconds',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function drops(): HasMany
    {
        return $this->hasMany(RvmDrop::class, 'template_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }
}
