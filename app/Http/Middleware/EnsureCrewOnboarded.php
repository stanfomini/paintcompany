<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCrewOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('crew');

        if ($user && $user->needs_onboarding) {
            return redirect()->route('crew.onboarding');
        }

        return $next($request);
    }
}
