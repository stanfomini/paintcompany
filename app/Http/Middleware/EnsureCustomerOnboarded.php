<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('customer');

        if ($user && $user->needs_onboarding) {
            return redirect()->route('customer.onboarding');
        }

        return $next($request);
    }
}
