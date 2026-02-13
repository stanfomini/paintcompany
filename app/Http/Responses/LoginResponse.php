<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = Auth::guard('web')->user();

        if (! $user || $user->isInternal()) {
            return redirect()->intended(Fortify::redirects('login'));
        }

        // Non-internal user authenticated via Fortify (web guard).
        // Move them to their correct guard and redirect to their dashboard.
        $guard = match ($user->type) {
            'customer' => 'customer',
            'agent' => 'agent',
            'crew' => 'crew',
            default => null,
        };

        if ($guard) {
            Auth::guard($guard)->login($user, $request->boolean('remember'));
            Auth::guard('web')->logoutCurrentDevice();

            $dashboard = match ($user->type) {
                'customer' => route('customer.dashboard'),
                'agent' => route('agent.dashboard'),
                'crew' => route('crew.dashboard'),
                default => route('dashboard'),
            };

            return redirect($dashboard);
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
