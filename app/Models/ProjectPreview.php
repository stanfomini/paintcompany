<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ProjectPreview extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'public_token',
        'customer_id',
        'deal_id',
        'estimate_id',
        'phone',
        'first_name',
        'last_name',
        'email',
        'address',
        'lead_source_id',
        'appointment_booking_id',
        'scope_type',
        'rooms_data',
        'timeline',
        'notes',
        'estimator_id',
        'status',
        'portal_views_count',
        'last_viewed_at',
        'questionnaire_data',
        'questionnaire_completed_at',
        'questionnaire_discount_percent',
        'preview_link_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'rooms_data' => 'array',
            'questionnaire_data' => 'array',
            'questionnaire_completed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'portal_views_count' => 'integer',
            'preview_link_sent_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->public_token)) {
                $model->public_token = self::generateSlugToken();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function appointmentBooking(): BelongsTo
    {
        return $this->belongsTo(AppointmentBooking::class);
    }

    public function estimator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'estimator_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ProjectPreviewPhoto::class);
    }

    public function visualizations(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProjectPreviewVisualization::class,
            ProjectPreviewPhoto::class,
        );
    }

    public function smsConversation(): HasOne
    {
        return $this->hasOne(SmsConversation::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(ProjectPreviewView::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeConverted(Builder $query): Builder
    {
        return $query->where('status', 'converted');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    /**
     * Generate a slug-style token like "amber-river-fox-k9m2".
     * Human-readable but unguessable (~10B+ combinations.
     */
    public static function generateSlugToken(): string
    {
        $adjectives = ['amber', 'bold', 'calm', 'deep', 'easy', 'fair', 'gold', 'hazy', 'iron', 'jade',
            'keen', 'lush', 'mint', 'navy', 'opal', 'pale', 'ruby', 'sage', 'teal', 'warm',
            'aqua', 'blue', 'clay', 'dawn', 'fern', 'gray', 'ivory', 'lime', 'moss', 'plum',
            'rose', 'sand', 'stone', 'snow', 'wild', 'soft', 'rich', 'pure', 'cool', 'crisp'];
        $nouns = ['brook', 'cedar', 'delta', 'ember', 'field', 'grove', 'haven', 'lake', 'maple', 'north',
            'ocean', 'peak', 'ridge', 'shore', 'trail', 'vale', 'creek', 'dusk', 'frost', 'glen',
            'hawk', 'isle', 'knoll', 'lodge', 'marsh', 'nest', 'pine', 'reef', 'slate', 'stone',
            'tide', 'wind', 'birch', 'bluff', 'cliff', 'cove', 'fern', 'hill', 'mesa', 'rain'];

        do {
            $slug = $adjectives[array_rand($adjectives)]
                .'-'.$nouns[array_rand($nouns)]
                .'-'.$adjectives[array_rand($adjectives)]
                .'-'.Str::lower(Str::random(4));
        } while (self::where('public_token', $slug)->exists());

        return $slug;
    }

    /**
     * Get the public portal URL for this preview.
     */
    public function getPortalUrl(): string
    {
        return url("/preview/{$this->public_token}");
    }

    /**
     * Record a view and increment the portal views counter.
     */
    public function isQuestionnaireCompleted(): bool
    {
        return $this->questionnaire_completed_at !== null;
    }

    public function questionnaireProgress(): int
    {
        $data = $this->questionnaire_data ?? [];
        $completed = 0;

        for ($i = 1; $i <= 5; $i++) {
            if (! empty($data["step_{$i}"])) {
                $completed++;
            }
        }

        return $completed;
    }

    public function recordView(?User $user = null): ProjectPreviewView
    {
        $this->increment('portal_views_count');
        $this->update(['last_viewed_at' => now()]);

        return ProjectPreviewView::record($this, $user);
    }
}
