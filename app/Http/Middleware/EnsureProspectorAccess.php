<?php

namespace App\Http\Middleware;

use App\Models\Prospector\Agent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureProspectorAccess
{
    /**
     * Handle an incoming request.
     *
     * Behavior:
     * 1. If no admin exists yet, redirect to setup page
     * 2. If user has no agent record, deny access
     * 3. If a permission is required, check it
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        // First-run: no admin exists yet, redirect to setup (unless we're already there)
        if (! Agent::hasAnyAdmin()) {
            if ($request->routeIs('prospector.setup')) {
                return $next($request);
            }

            return redirect()->route('prospector.setup');
        }

        // After setup: setup page is no longer accessible
        if ($request->routeIs('prospector.setup')) {
            return redirect()->route('prospector.dashboard');
        }

        // User must be internal
        if ($user->type !== 'internal') {
            abort(403, 'Internal users only.');
        }

        // User must have an active agent record
        $agent = Agent::forCurrentUser();

        if (! $agent) {
            abort(403, 'You do not have access to the Prospector module. Ask an administrator to grant you access.');
        }

        // Check permission if specified
        if ($permission && ! $agent->hasPermission($permission)) {
            abort(403, 'You do not have permission to access this resource.');
        }

        return $next($request);
    }
}
