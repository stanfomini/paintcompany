<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserTypeMismatchException extends Exception implements ShouldntReport
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
            return __('Please log in to access this area.');
        }

        $expectedAreaName = match ($this->expectedType) {
            'agent' => __('agent portal'),
            'customer' => __('customer portal'),
            'crew' => __('crew portal'),
            'internal' => __('admin area'),
            default => __('this area'),
        };

        $actualTypeName = match ($this->actualType) {
            'internal' => __('internal team member'),
            'agent' => __('agent'),
            'customer' => __('customer'),
            'crew' => __('crew member'),
            default => $this->actualType,
        };

        return __('The :area is not accessible with your account. You are logged in as a :type.', [
            'area' => $expectedAreaName,
            'type' => $actualTypeName,
        ]);
    }

    /**
     * Render the exception as a redirect response.
     *
     * Uses explicit RedirectResponse to avoid Livewire's Redirector type issues.
     */
    public function render(Request $request): RedirectResponse
    {
        if ($this->actualType !== null) {
            // Determine which guard this user should be on vs which they're actually on
            $correctGuard = match ($this->actualType) {
                'internal' => 'web',
                'agent' => 'agent',
                'customer' => 'customer',
                'crew' => 'crew',
                default => 'web',
            };

            // Check if user is on the wrong guard (e.g. customer authenticated on web guard).
            // If so, log them out to prevent redirect loops and send to correct login.
            if ($this->intendedGuard && $this->intendedGuard !== $correctGuard) {
                Auth::guard($this->intendedGuard)->logoutCurrentDevice();
                session()->flash('warning', $this->getUserMessage());

                $loginRoute = match ($this->actualType) {
                    'agent' => 'agent.login',
                    'customer' => 'customer.login',
                    'crew' => 'crew.login',
                    default => 'login',
                };

                return new RedirectResponse(route($loginRoute));
            }

            // User is on the correct guard but wrong area - redirect to their dashboard
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
