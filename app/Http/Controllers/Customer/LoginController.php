<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Concerns\HandlesMultiGuardLogin;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    use HandlesMultiGuardLogin;

    public function showLoginForm(Request $request): View
    {
        return view('customer.auth.login', [
            'returnUrl' => $request->query('return'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Add type check to credentials
        $credentials['type'] = 'customer';
        $credentials['is_active'] = true;

        if (Auth::guard('customer')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Clear intended URL if it's for a different guard's area
            $this->clearCrossGuardIntendedUrl($request, 'customer');

            // Logout from other guards to prevent session confusion
            $this->logoutOtherGuards($request, 'customer');

            // Handle return URL from preview page
            $returnUrl = $request->query('return');
            if ($returnUrl && $this->isValidPreviewReturnUrl($returnUrl)) {
                // Extract token from /preview/{token} URL
                $path = parse_url($returnUrl, PHP_URL_PATH);
                if (preg_match('#^/preview/([a-z0-9-]+)$#', $path, $matches)) {
                    $request->session()->put('preview_return_token', $matches[1]);
                }
            }

            return redirect()->intended(route('customer.dashboard'));
        }

        // Check if credentials are valid but for a different user type
        if ($redirect = $this->redirectIfWrongPortal($request, 'customer')) {
            return $redirect;
        }

        return back()->withErrors([
            'email' => __('The provided credentials do not match our records or your account is not active.'),
        ])->onlyInput('email');
    }

    /**
     * Validate that a return URL is a safe preview URL (prevent open redirect).
     */
    private function isValidPreviewReturnUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (! $parsed || ! isset($parsed['path'])) {
            return false;
        }

        // Must be a relative URL or match our app URL
        if (isset($parsed['host'])) {
            $appHost = parse_url(config('app.url'), PHP_URL_HOST);
            if ($parsed['host'] !== $appHost) {
                return false;
            }
        }

        return (bool) preg_match('#^/preview/[a-z0-9-]+$#', $parsed['path']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('customer.login');
    }
}
