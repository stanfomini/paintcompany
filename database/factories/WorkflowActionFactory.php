<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\WorkflowAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkflowAction>
 */
class WorkflowActionFactory extends Factory
{
    protected $model = WorkflowAction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'action_type' => WorkflowAction::ACTION_CREATE_COMMISSION,
            'action_config' => ['type' => 'revenue_percentage', 'rate' => 0.10],
            'target_user_field' => 'assigned_to',
            'debit_account_code' => null,
            'credit_account_code' => null,
            'order' => 1,
        ];
    }

    /**
     * Set action to create a flat rate commission.
     */
    public function flatRate(float $amount): static
    {
        return $this->state(fn () => [
            'action_type' => WorkflowAction::ACTION_CREATE_FLAT_RATE,
            'action_config' => ['amount' => $amount],
        ]);
    }

    /**
     * Set action to create a payable.
     */
    public function createPayable(string $formula): static
    {
        return $this->state(fn () => [
            'action_type' => WorkflowAction::ACTION_CREATE_PAYABLE,
            'action_config' => ['formula' => $formula],
        ]);
    }

    /**
     * Set action to update a field on the model.
     */
    public function updateField(string $field, mixed $value): static
    {
        return $this->state(fn () => [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => $field, 'value' => $value],
        ]);
    }

    /**
     * Set action to send a notification.
     */
    public function sendNotification(string $message): static
    {
        return $this->state(fn () => [
            'action_type' => WorkflowAction::ACTION_SEND_NOTIFICATION,
            'action_config' => ['message' => $message],
        ]);
    }
}
