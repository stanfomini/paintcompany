<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowAction extends Model
{
    use HasFactory;

    public const ACTION_CREATE_COMMISSION = 'create_commission';

    public const ACTION_CREATE_FLAT_RATE = 'create_flat_rate';

    public const ACTION_CREATE_PAYABLE = 'create_payable';

    public const ACTION_UPDATE_FIELD = 'update_field';

    public const ACTION_SEND_NOTIFICATION = 'send_notification';

    public const ACTION_CREATE_INVOICE = 'create_invoice';

    protected $fillable = [
        'workflow_id',
        'action_type',
        'action_config',
        'target_user_field',
        'debit_account_code',
        'credit_account_code',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'action_config' => 'array',
            'order' => 'integer',
        ];
    }

    /**
     * Get the workflow this action belongs to.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
