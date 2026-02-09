<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateActivity extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateActivityFactory> */
    use HasFactory;

    public const ACTIVITY_TYPES = [
        'viewed' => 'Viewed',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'option_toggled' => 'Option Toggled',
        'optional_item_toggled' => 'Add-on Toggled',
        'note_added' => 'Note Added',
        'signature_added' => 'Signature Added',
        'photo_added' => 'Photo Added',
        'amendment_created' => 'Amendment Created',
        'amendment_acknowledged' => 'Amendment Acknowledged',
        'package_selected' => 'Package Selected',
        'package_tier_selected' => 'Package Tier Selected',
        'packages_modified' => 'Package Modified',
    ];

    protected $fillable = [
        'estimate_id',
        'user_id',
        'activity_type',
        'description',
        'metadata',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Get the activity type label.
     */
    public function getActivityTypeLabelAttribute(): string
    {
        return self::ACTIVITY_TYPES[$this->activity_type] ?? $this->activity_type;
    }

    /**
     * Get the estimate for this activity.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the user who performed this activity.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log an activity for an estimate.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function log(
        Estimate $estimate,
        string $type,
        ?string $description = null,
        ?array $metadata = null
    ): self {
        return static::create([
            'estimate_id' => $estimate->id,
            'user_id' => auth()->id(),
            'activity_type' => $type,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Scope to filter by activity type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('activity_type', $type);
    }
}
