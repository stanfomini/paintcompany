<?php

namespace App\Notifications\Concerns;

/**
 * Trait to restrict notifications to specific user types.
 *
 * Usage in a notification class:
 *
 *     use RestrictsToUserTypes;
 *
 *     protected array $allowedUserTypes = ['internal'];
 *
 * Then in via():
 *     if (! $this->isAllowedRecipient($notifiable)) { return []; }
 */
trait RestrictsToUserTypes
{
    /**
     * Check if the notifiable user is an allowed recipient type.
     */
    protected function isAllowedRecipient(object $notifiable): bool
    {
        if (! property_exists($this, 'allowedUserTypes') || empty($this->allowedUserTypes)) {
            return true;
        }

        if (! method_exists($notifiable, 'getAttribute')) {
            return true;
        }

        $type = $notifiable->getAttribute('type');

        return in_array($type, $this->allowedUserTypes, true);
    }
}
