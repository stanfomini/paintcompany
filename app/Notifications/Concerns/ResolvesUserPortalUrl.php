<?php

namespace App\Notifications\Concerns;

trait ResolvesUserPortalUrl
{
    private function getEstimateUrl(object $notifiable): string
    {
        $estimateId = $this->activity->estimate_id;

        if ($estimateId) {
            if ($notifiable->isCustomer()) {
                return route('customer.estimates.show', $estimateId);
            }

            if ($notifiable->isAgent()) {
                return route('agent.estimates.show', $estimateId);
            }

            return route('estimates.show', $estimateId);
        }

        return $this->getDashboardUrl($notifiable);
    }

    private function getVideoCallUrl(object $notifiable): string
    {
        if ($this->activity->videoCall) {
            return route('video-call.test', $this->activity->videoCall->room_id);
        }

        return $this->getEstimateUrl($notifiable);
    }

    private function getDashboardUrl(object $notifiable): string
    {
        if ($notifiable->isCustomer()) {
            return route('customer.dashboard');
        }

        if ($notifiable->isAgent()) {
            return route('agent.dashboard');
        }

        if ($notifiable->isCrew()) {
            return route('crew.dashboard');
        }

        return route('dashboard');
    }
}
