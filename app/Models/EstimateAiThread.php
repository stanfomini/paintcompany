<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateAiThread extends Model
{
    protected $fillable = [
        'estimate_id',
        'name',
        'is_processing',
        'cache_key',
    ];

    protected function casts(): array
    {
        return [
            'is_processing' => 'boolean',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EstimateAiMessage::class, 'thread_id')->orderBy('created_at');
    }

    /**
     * Get conversation history formatted for the AI agent.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function getConversationHistory(): array
    {
        return $this->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();
    }

    public function markProcessing(string $cacheKey): void
    {
        $this->update([
            'is_processing' => true,
            'cache_key' => $cacheKey,
        ]);
    }

    public function markComplete(): void
    {
        $this->update([
            'is_processing' => false,
            'cache_key' => null,
        ]);
    }
}
