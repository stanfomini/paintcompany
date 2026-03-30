<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    use HasFactory;

    protected $table = 'prospector_sms_messages';

    protected $fillable = [
        'company_id',
        'contact_id',
        'agent_id',
        'direction',
        'from_number',
        'to_number',
        'body',
        'media_urls',
        'twilio_sid',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'media_urls' => 'array',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    public function scopeForContact(Builder $query, int $contactId): void
    {
        $query->where('contact_id', $contactId);
    }

    public function scopeInbound(Builder $query): void
    {
        $query->where('direction', 'inbound');
    }

    public function scopeOutbound(Builder $query): void
    {
        $query->where('direction', 'outbound');
    }

    public function scopeRecent(Builder $query): void
    {
        $query->where('created_at', '>=', now()->subDay());
    }
}
