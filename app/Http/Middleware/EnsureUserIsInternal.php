<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        if (! $request->user() || ! $request->user()->isInternal()) {
            abort(403, 'Access denied. Internal users only.');
        }

        if (! $request->user()->isActive()) {
            abort(403, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
