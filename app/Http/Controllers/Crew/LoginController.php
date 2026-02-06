<?php

namespace App\Http\Controllers\Crew;

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
        return view('crew.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Add type check to credentials
        $credentials['type'] = 'crew';
        $credentials['is_active'] = true;

        if (Auth::guard('crew')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Clear intended URL if it's for a different guard's area
            $this->clearCrossGuardIntendedUrl($request, 'crew');

            // Logout from other guards to prevent session confusion
            $this->logoutOtherGuards($request, 'crew');

            return redirect()->intended(route('crew.dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records or your account is not active.',
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('crew')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('crew.login');
    }
}
