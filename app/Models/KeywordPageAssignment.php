<?php

namespace App\Models;

use App\Observers\KeywordPageAssignmentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[ObservedBy(KeywordPageAssignmentObserver::class)]
class KeywordPageAssignment extends Model
{
    /** @use HasFactory<\Database\Factories\KeywordPageAssignmentFactory> */
    use HasFactory;

    public const ROLES = ['primary', 'secondary', 'supporting'];

    protected $fillable = [
        'keyword_id',
        'service_id',
        'service_area_id',
        'role',
        'custom_sentence',
        'position',
        'impressions',
        'clicks',
        'ctr',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'decimal:2',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'ctr' => 'decimal:4',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $assignment) {
            // Application-layer guard for the CHECK constraint (so SQLite tests
            // and any pre-MySQL-8 environments enforce the same invariant).
            if ($assignment->service_id === null && $assignment->service_area_id === null) {
                throw new RuntimeException('A keyword page assignment must target at least one of service_id or service_area_id.');
            }
        });
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /**
     * Specificity ranking used by the resolver:
     * 3 = combo-specific (both FKs set)
     * 2 = service-only fallback
     * 1 = area-only fallback
     */
    public function getSpecificityAttribute(): int
    {
        if ($this->service_id !== null && $this->service_area_id !== null) {
            return 3;
        }

        if ($this->service_id !== null) {
            return 2;
        }

        return 1;
    }

    /**
     * Whether this assignment applies to the given (service, area) page.
     */
    public function matches(?Service $service, ?ServiceArea $serviceArea): bool
    {
        $serviceId = $service?->id;
        $areaId = $serviceArea?->id;

        // Combo-specific assignment matches only when both IDs line up exactly.
        if ($this->service_id !== null && $this->service_area_id !== null) {
            return $this->service_id === $serviceId && $this->service_area_id === $areaId;
        }

        // Service-only assignment matches any page that includes that service.
        if ($this->service_id !== null) {
            return $this->service_id === $serviceId;
        }

        // Area-only assignment matches any page that includes that area.
        return $this->service_area_id === $areaId;
    }
}
