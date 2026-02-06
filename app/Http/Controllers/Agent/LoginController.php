<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Concerns\HandlesMultiGuardLogin;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    use HandlesMultiGuardLogin;

    public function showLoginForm(): View
    {
        return view('agent.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Add type check to credentials
        $credentials['type'] = 'agent';
        $credentials['is_active'] = true;

        if (Auth::guard('agent')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Clear intended URL if it's for a different guard's area
            $this->clearCrossGuardIntendedUrl($request, 'agent');

            // Logout from other guards to prevent session confusion
            $this->logoutOtherGuards($request, 'agent');

            return redirect()->intended(route('agent.dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records or your account is not active.',
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('agent')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('agent.login');
    }
}
