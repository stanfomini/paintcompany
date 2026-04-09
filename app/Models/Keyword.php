<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Keyword extends Model
{
    /** @use HasFactory<\Database\Factories\KeywordFactory> */
    use HasFactory;

    public const STATUSES = ['researched', 'targeted', 'ranking', 'won', 'dropped'];

    public const SOURCES = ['keyword_planner', 'search_console', 'manual', 'ai_suggested'];

    public const INTENTS = ['informational', 'commercial', 'transactional', 'navigational', 'local'];

    public const COMPETITIONS = ['low', 'medium', 'high'];

    protected $fillable = [
        'keyword',
        'search_volume',
        'competition',
        'cpc',
        'intent',
        'status',
        'source',
        'default_sentence',
        'notes',
        'last_researched_at',
    ];

    protected function casts(): array
    {
        return [
            'search_volume' => 'integer',
            'cpc' => 'decimal:2',
            'last_researched_at' => 'datetime',
        ];
    }

    public function setKeywordAttribute(string $value): void
    {
        $this->attributes['keyword'] = Str::lower(trim($value));
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(KeywordPageAssignment::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'keyword_page_assignments')
            ->withPivot(['role', 'custom_sentence', 'service_area_id'])
            ->withTimestamps();
    }

    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(ServiceArea::class, 'keyword_page_assignments')
            ->withPivot(['role', 'custom_sentence', 'service_id'])
            ->withTimestamps();
    }

    /**
     * Find the assignment for a given (service, area) combo, if any.
     * Honors the specificity hierarchy: combo > service-only > area-only.
     */
    public function assignmentFor(?Service $service, ?ServiceArea $serviceArea): ?KeywordPageAssignment
    {
        return $this->assignments
            ->filter(fn (KeywordPageAssignment $a) => $a->matches($service, $serviceArea))
            ->sortByDesc('specificity')
            ->first();
    }

    /**
     * Resolve the contextual sentence for this keyword on a given page,
     * cascading from per-assignment custom_sentence → global default_sentence → null.
     */
    public function customSentenceFor(?Service $service, ?ServiceArea $serviceArea): ?string
    {
        return $this->assignmentFor($service, $serviceArea)?->custom_sentence
            ?? $this->default_sentence;
    }

    /**
     * Scope to keywords assigned to a specific service+area combo, including fallbacks
     * (combo-specific, service-only, area-only). Returns a deduped, ordered builder.
     */
    public function scopeForCombo(Builder $query, Service $service, ServiceArea $serviceArea): Builder
    {
        return $query->whereHas('assignments', function (Builder $q) use ($service, $serviceArea) {
            $q->where(function (Builder $inner) use ($service, $serviceArea) {
                $inner->where(function (Builder $combo) use ($service, $serviceArea) {
                    $combo->where('service_id', $service->id)
                        ->where('service_area_id', $serviceArea->id);
                })->orWhere(function (Builder $svcOnly) use ($service) {
                    $svcOnly->where('service_id', $service->id)
                        ->whereNull('service_area_id');
                })->orWhere(function (Builder $areaOnly) use ($serviceArea) {
                    $areaOnly->whereNull('service_id')
                        ->where('service_area_id', $serviceArea->id);
                });
            });
        });
    }

    public function scopeForServiceOnly(Builder $query, Service $service): Builder
    {
        return $query->whereHas('assignments', function (Builder $q) use ($service) {
            $q->where('service_id', $service->id)->whereNull('service_area_id');
        });
    }

    public function scopeForAreaOnly(Builder $query, ServiceArea $serviceArea): Builder
    {
        return $query->whereHas('assignments', function (Builder $q) use ($serviceArea) {
            $q->whereNull('service_id')->where('service_area_id', $serviceArea->id);
        });
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    public function scopeIntent(Builder $query, string $intent): Builder
    {
        return $query->where('intent', $intent);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where('keyword', 'like', '%'.Str::lower(trim($term)).'%');
    }
}
