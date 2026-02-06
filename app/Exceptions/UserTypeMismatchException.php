<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserTypeMismatchException extends Exception
{
    public function __construct(
        public string $expectedType,
        public ?string $actualType = null,
        public ?string $intendedGuard = null,
        string $message = 'Access denied.'
    ) {
        parent::__construct($message);
    }

    /**
     * Get the appropriate login route for the expected user type.
     */
    public function getLoginRoute(): string
    {
        return match ($this->expectedType) {
            'agent' => 'agent.login',
            'customer' => 'customer.login',
            'crew' => 'crew.login',
            default => 'login',
        };
    }

    /**
     * Get the appropriate dashboard route for the actual user type.
     */
    public function getDashboardRoute(): ?string
    {
        return match ($this->actualType) {
            'internal' => 'dashboard',
            'agent' => 'agent.dashboard',
            'customer' => 'customer.dashboard',
            'crew' => 'crew.dashboard',
            default => null,
        };
    }

    /**
     * Get a user-friendly message explaining what happened.
     */
    public function getUserMessage(): string
    {
        if ($this->actualType === null) {
            return 'Please log in to access this area.';
        }

        $expectedAreaName = match ($this->expectedType) {
            'agent' => 'agent portal',
            'customer' => 'customer portal',
            'crew' => 'crew portal',
            'internal' => 'admin area',
            default => 'this area',
        };

        $actualTypeName = match ($this->actualType) {
            'internal' => 'internal team member',
            'agent' => 'agent',
            'customer' => 'customer',
            'crew' => 'crew member',
            default => $this->actualType,
        };

        return "The {$expectedAreaName} is not accessible with your account. You are logged in as a {$actualTypeName}.";
    }

    /**
     * Render the exception as a redirect response.
     *
     * Uses explicit RedirectResponse to avoid Livewire's Redirector type issues.
     */
    public function render(Request $request): RedirectResponse
    {
        // If user is logged in as wrong type, redirect to their dashboard
        if ($this->actualType !== null) {
            $dashboardRoute = $this->getDashboardRoute();

            if ($dashboardRoute) {
                session()->flash('warning', $this->getUserMessage());

                return new RedirectResponse(route($dashboardRoute));
            }
        }

        // If not logged in, redirect to appropriate login
        session()->flash('error', $this->getUserMessage());

        return new RedirectResponse(route($this->getLoginRoute()));
    }
}
