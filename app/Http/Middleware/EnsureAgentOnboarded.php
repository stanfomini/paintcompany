<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('agent');

        if ($user && $user->needs_onboarding) {
            return redirect()->route('agent.onboarding');
        }

        return $next($request);
    }
}
