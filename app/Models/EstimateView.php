<?php

namespace App\Models;

use App\Notifications\ProposalViewed;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class EstimateView extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateViewFactory> */
    use HasFactory;

    protected $fillable = [
        'estimate_id',
        'user_id',
        'viewed_at',
        'ip_address',
        'user_agent',
        'referrer',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    /**
     * Get the estimate for this view.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the user who viewed (nullable).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a view for an estimate.
     */
    public static function record(Estimate $estimate, ?User $user = null): self
    {
        $view = static::create([
            'estimate_id' => $estimate->id,
            'user_id' => $user?->id,
            'viewed_at' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'referrer' => request()->header('referer'),
        ]);

        static::notifyAssigneeOfView($estimate);

        return $view;
    }

    /**
     * Send a throttled notification when a proposal is viewed.
     */
    protected static function notifyAssigneeOfView(Estimate $estimate): void
    {
        $cacheKey = "proposal_viewed_notify:{$estimate->id}";

        if (Cache::has($cacheKey)) {
            return;
        }

        $estimate->loadMissing('deal.customer.assignedTo');
        $deal = $estimate->deal;
        $assignee = $deal?->assignedTo ?? $deal?->customer?->assignedTo;

        if ($assignee) {
            $assignee->notify(new ProposalViewed($estimate));
            Cache::put($cacheKey, true, now()->addHour());
        }
    }
}
