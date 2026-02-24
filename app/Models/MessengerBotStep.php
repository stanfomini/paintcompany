<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessengerBotStep extends Model
{
    use HasFactory;

    public const TYPE_GREETING = 'greeting';

    public const TYPE_TEXT_QUESTION = 'text_question';

    public const TYPE_QUICK_REPLY = 'quick_reply';

    public const TYPE_DELAY = 'delay';

    public const TYPE_HANDOFF = 'handoff';

    protected $fillable = [
        'flow_id',
        'sort_order',
        'step_type',
        'message_text',
        'quick_reply_options',
        'field_mapping',
        'is_required',
        'delay_seconds',
    ];

    protected function casts(): array
    {
        return [
            'quick_reply_options' => 'array',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'delay_seconds' => 'integer',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(MessengerBotFlow::class, 'flow_id');
    }

    /**
     * Get the list of fields that can be mapped to bot step answers.
     */
    public static function getMappableFields(): array
    {
        return [
            'Customer Fields' => [
                'customer.first_name' => 'First Name',
                'customer.last_name' => 'Last Name',
                'customer.email' => 'Email',
                'customer.phone' => 'Phone',
                'customer.address' => 'Address',
                'customer.city' => 'City',
                'customer.state' => 'State',
                'customer.zip' => 'ZIP Code',
                'customer.notes' => 'Notes',
            ],
            'Deal Fields' => [
                'deal.title' => 'Deal Title',
                'deal.description' => 'Description',
                'deal.lead_source' => 'Lead Source',
            ],
        ];
    }

    public function isQuestion(): bool
    {
        return in_array($this->step_type, [self::TYPE_TEXT_QUESTION, self::TYPE_QUICK_REPLY]);
    }

    public function requiresResponse(): bool
    {
        return $this->isQuestion();
    }
}
