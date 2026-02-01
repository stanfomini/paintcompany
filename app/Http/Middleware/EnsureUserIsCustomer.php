<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsCustomer
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user('customer') || ! $request->user('customer')->isCustomer()) {
            abort(403, 'Access denied. Customer users only.');
        }

        if (! $request->user('customer')->isActive()) {
            abort(403, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
