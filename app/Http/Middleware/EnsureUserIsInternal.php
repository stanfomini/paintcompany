<?php

namespace App\Http\Middleware;

use App\Exceptions\UserTypeMismatchException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsInternal
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not authenticated with web guard
        if (! $user || ! $user->isInternal()) {
            throw new UserTypeMismatchException(
                expectedType: 'internal',
                actualType: $this->getAuthenticatedUserType(),
                intendedGuard: 'web'
            );
        }

        // Account deactivated
        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            session()->flash('error', 'Your account has been deactivated. Please contact support.');

            return new \Illuminate\Http\RedirectResponse(route('login'));
        }

        return $next($request);
    }

    /**
     * Check if user is authenticated with any guard and return their type.
     */
    private function getAuthenticatedUserType(): ?string
    {
        foreach (['web', 'agent', 'customer', 'crew'] as $guard) {
            if (Auth::guard($guard)->check()) {
                return Auth::guard($guard)->user()->type;
            }
        }

        return null;
    }
}
