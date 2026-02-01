<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_id',
        'user_id',
        'parent_id',
        'content',
        'is_internal',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    /**
     * Get the estimate this note belongs to.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the user who wrote this note.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent note (if this is a reply).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(EstimateNote::class, 'parent_id');
    }

    /**
     * Get the replies to this note.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(EstimateNote::class, 'parent_id');
    }

    /**
     * Scope to filter internal notes only.
     */
    public function scopeInternal($query)
    {
        return $query->where('is_internal', true);
    }

    /**
     * Scope to filter external notes (visible to customer).
     */
    public function scopeExternal($query)
    {
        return $query->where('is_internal', false);
    }

    /**
     * Scope to filter root notes (not replies).
     */
    public function scopeRootNotes($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Create a note from the current request, capturing IP address.
     */
    public static function createFromRequest(Estimate $estimate, string $content, bool $isInternal = false): static
    {
        return static::create([
            'estimate_id' => $estimate->id,
            'user_id' => auth()->id(),
            'content' => $content,
            'is_internal' => $isInternal,
            'ip_address' => request()->ip(),
        ]);
    }
}
