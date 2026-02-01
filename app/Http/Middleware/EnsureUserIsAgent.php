<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAgent
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user('agent') || ! $request->user('agent')->isAgent()) {
            abort(403, 'Access denied. Agent users only.');
        }

        if (! $request->user('agent')->isActive()) {
            abort(403, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
