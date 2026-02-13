<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

trait HandlesMultiGuardLogin
{
    /**
     * The guard areas and their URL prefixes.
     */
    protected array $guardAreas = [
        'web' => ['/', '/dashboard', '/customers', '/properties', '/deals', '/estimates', '/settings'],
        'agent' => ['/agent/'],
        'customer' => ['/customer/'],
        'crew' => ['/crew/'],
    ];

    /**
     * Clear intended URL if it points to a different guard's area.
     */
    protected function clearCrossGuardIntendedUrl(Request $request, string $currentGuard): void
    {
        $intended = $request->session()->get('url.intended');

        if (! $intended) {
            return;
        }

        $path = parse_url($intended, PHP_URL_PATH) ?? '';

        // Check if intended URL matches current guard's area
        $belongsToCurrentGuard = $this->urlBelongsToGuard($path, $currentGuard);

        // If it doesn't belong to current guard, clear it
        if (! $belongsToCurrentGuard) {
            $request->session()->forget('url.intended');
        }
    }

    /**
     * Check if a URL path belongs to a specific guard's area.
     */
    protected function urlBelongsToGuard(string $path, string $guard): bool
    {
        $prefixes = $this->guardAreas[$guard] ?? [];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Logout from other guards to prevent session confusion.
     */
    protected function logoutOtherGuards(Request $request, string $currentGuard): void
    {
        $guards = ['web', 'agent', 'customer', 'crew'];

        foreach ($guards as $guard) {
            if ($guard !== $currentGuard && Auth::guard($guard)->check()) {
                Auth::guard($guard)->logoutCurrentDevice();
            }
        }
    }

    /**
     * Get the dashboard route for a given guard.
     */
    protected function getDashboardRoute(string $guard): string
    {
        return match ($guard) {
            'agent' => 'agent.dashboard',
            'customer' => 'customer.dashboard',
            'crew' => 'crew.dashboard',
            default => 'dashboard',
        };
    }

    /**
     * Get the login route for a given guard.
     */
    protected function getLoginRoute(string $guard): string
    {
        return match ($guard) {
            'agent' => 'agent.login',
            'customer' => 'customer.login',
            'crew' => 'crew.login',
            default => 'login',
        };
    }

    /**
     * Check if valid credentials were used on the wrong portal and redirect to the correct one.
     *
     * Returns a redirect if the user exists with valid credentials but is the wrong type.
     * Returns null if this is not a wrong-portal situation (invalid credentials or correct portal).
     */
    protected function redirectIfWrongPortal(Request $request, string $expectedType): ?RedirectResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return null;
        }

        if ($user->type === $expectedType) {
            return null;
        }

        $correctGuard = match ($user->type) {
            'internal' => 'web',
            'agent' => 'agent',
            'customer' => 'customer',
            'crew' => 'crew',
            default => 'web',
        };

        // Log them in on their correct guard and redirect
        Auth::guard($correctGuard)->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $dashboard = $this->getDashboardRoute($correctGuard);

        return redirect()->route($dashboard);
    }
}
