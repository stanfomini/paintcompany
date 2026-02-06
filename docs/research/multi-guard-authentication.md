# Research: Laravel 12 Multi-Guard Authentication Best Practices

*Generated: 2026-02-05*

## Current Implementation Analysis

Your Apex Negotiations application uses a **single-table multi-auth** pattern:
- Single `User` model with `type` column (`internal`, `agent`, `customer`, `crew`)
- Four session guards (`web`, `agent`, `customer`, `crew`) all pointing to the same `User` model
- Route-prefix-based guard routing (`/agent/*`, `/customer/*`, `/crew/*`)
- Shared session cookie (default Laravel behavior)

## 1. Session Isolation for Multiple Guards

### Should Each Guard Have Its Own Session Cookie?

**Recommendation: No - Keep Shared Session (Your Current Approach)**

#### Pros of Shared Session (Current)
- Simpler implementation and maintenance
- Single user identity per browser session
- Prevents confusion when same user has multiple types
- Works well with your single-table architecture
- No cookie management complexity

#### Cons of Shared Session
- Logging out from one guard can affect others (if `invalidate()` is called)
- `url.intended` conflicts between guards (discussed below)

#### When Separate Cookies Make Sense
- **Multi-tenant applications** where users can be different entities
- **Different subdomains** per user type (e.g., `admin.example.com`, `app.example.com`)
- When the same person legitimately needs simultaneous sessions as different user types

### How to Implement Separate Session Cookies (If Needed)

If you ever need separate cookies, create middleware that sets the session name dynamically:

```php
// app/Http/Middleware/SetSessionCookie.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;

class SetSessionCookie
{
    public function handle(Request $request, Closure $next, string $prefix): mixed
    {
        $cookieName = config('app.name') . '_' . $prefix . '_session';

        Config::set('session.cookie', $cookieName);
        Session::setName($cookieName);

        return $next($request);
    }
}
```

Register in `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->prependToGroup('web', SetSessionCookie::class.':web');
    $middleware->prependToGroup('agent', SetSessionCookie::class.':agent');
    // etc.
});
```

**Important**: Laravel creates the session before middleware runs, so you must use `prependToGroup` and the middleware ordering is critical.

### Current Best Practice for Your Setup

Since all providers point to the same `User` model, **shared sessions are correct**. The database session driver you're using (`'driver' => 'database'`) already provides good isolation and traceability.

---

## 2. Redirect Handling for Multi-Auth

### Your Current Implementation (Good)

```php
// bootstrap/app.php
$middleware->redirectGuestsTo(function ($request) {
    if ($request->is('agent/*')) {
        return route('agent.login');
    }
    if ($request->is('customer/*')) {
        return route('customer.login');
    }
    if ($request->is('crew/*')) {
        return route('crew.login');
    }
    return route('login');
});
```

This is the **correct Laravel 12 approach**.

### The `redirect()->intended()` Problem

**Problem**: Laravel stores `url.intended` in session without guard context. If an agent tries to access `/agent/dashboard`, then navigates to `/customer/login` and logs in, they might be redirected to `/agent/dashboard`.

**Solution 1: Guard-Specific Intended Keys**

Store intended URLs with guard prefix:

```php
// app/Http/Middleware/StoreIntendedUrl.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StoreIntendedUrl
{
    public function handle(Request $request, Closure $next, string $guard): mixed
    {
        // Store the intended URL with guard-specific key
        if ($request->isMethod('GET') && !$request->ajax()) {
            session()->put("url.intended.{$guard}", $request->fullUrl());
        }

        return $next($request);
    }
}
```

In your login controller/component:

```php
// After successful agent login
return redirect()->to(
    session()->pull('url.intended.agent', route('agent.dashboard'))
);
```

**Solution 2: Validate Intended URL Matches Guard (Recommended)**

```php
// In your login success handler
public function handleSuccessfulLogin(Request $request, string $guard): RedirectResponse
{
    $intended = session()->pull('url.intended');

    // Only use intended URL if it matches the current guard's prefix
    $guardPrefix = match ($guard) {
        'agent' => 'agent/',
        'customer' => 'customer/',
        'crew' => 'crew/',
        default => '',
    };

    if ($intended && str_contains($intended, $guardPrefix)) {
        return redirect()->to($intended);
    }

    // Fall back to guard-specific dashboard
    return redirect()->route("{$guard}.dashboard");
}
```

**Solution 3: Clear Intended on Login Page Load**

In your login page component:

```php
public function mount(): void
{
    // Clear any stale intended URLs when viewing login page
    session()->forget('url.intended');
}
```

### Best Practice Recommendation

**Use Solution 2** (validate intended URL matches guard prefix) as it:
- Works with Laravel's default `redirect()->intended()`
- Prevents cross-guard redirect hijacking
- Requires minimal changes to existing code

---

## 3. Exception Handling for 403s in Multi-Auth

### Current Issue in Your Middleware

```php
// app/Http/Middleware/EnsureUserIsInternal.php
if (! $request->user() || ! $request->user()->isInternal()) {
    abort(403, 'Access denied. Internal users only.');
}
```

**Problem**: This returns a 403 error page even for unauthenticated users who should be redirected to login.

### Recommended Pattern

Distinguish between **unauthenticated** (401 -> redirect to login) and **unauthorized** (403 -> show error):

```php
// app/Http/Middleware/EnsureUserIsInternal.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsInternal
{
    public function handle(Request $request, Closure $next): Response
    {
        // Not authenticated at all - redirect to login
        if (! $request->user()) {
            throw new AuthenticationException(
                'Unauthenticated.',
                ['web'],
                route('login')
            );
        }

        // Authenticated but not internal - show 403
        if (! $request->user()->isInternal()) {
            abort(403, 'Access denied. Internal users only.');
        }

        // Authenticated but deactivated - show 403
        if (! $request->user()->isActive()) {
            abort(403, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
```

### Custom 403 Handling Per Route Prefix

Add to `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
        // Don't customize for API requests
        if ($request->expectsJson()) {
            return null; // Use default JSON response
        }

        // Customize based on route prefix
        if ($request->is('agent/*')) {
            return response()->view('agent.errors.403', [
                'message' => $e->getMessage(),
            ], 403);
        }

        if ($request->is('customer/*')) {
            return response()->view('customer.errors.403', [
                'message' => $e->getMessage(),
            ], 403);
        }

        if ($request->is('crew/*')) {
            return response()->view('crew.errors.403', [
                'message' => $e->getMessage(),
            ], 403);
        }

        // Default 403 view for internal/web
        return null; // Use Laravel's default
    });
});
```

### Should 403s Redirect to Login?

**No** - A 403 (Forbidden) means "authenticated but not authorized". The user IS logged in but doesn't have permission.

**Best Practice**:
- **401 Unauthenticated** -> Redirect to appropriate login page
- **403 Forbidden** -> Show error page with helpful message

---

## 4. Guard Switching and Session Management

### Should Logging Into One Guard Invalidate Others?

**Recommendation: No - For Your Architecture**

Since all guards share the same `User` model, a user can only be one type at a time. When they log in via any guard, they're authenticating the same identity.

### Preventing Session Conflicts

**Problem**: Default logout calls `$request->session()->invalidate()` which logs out ALL guards.

**Solution**: Create custom logout logic per guard:

```php
// In your logout handler (Livewire component or controller)
public function logout(Request $request, string $guard): RedirectResponse
{
    Auth::guard($guard)->logout();

    // Regenerate session token for CSRF protection
    // but DON'T invalidate the entire session
    $request->session()->regenerateToken();

    return redirect()->route("{$guard}.login");
}
```

If you want complete logout (recommended for single-user-type architecture):

```php
public function logout(Request $request): RedirectResponse
{
    // Log out from all guards
    foreach (['web', 'agent', 'customer', 'crew'] as $guard) {
        Auth::guard($guard)->logout();
    }

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    // Redirect based on current context
    return $this->getLogoutRedirect($request);
}

private function getLogoutRedirect(Request $request): RedirectResponse
{
    if ($request->is('agent/*')) {
        return redirect()->route('agent.login');
    }
    // ... etc
    return redirect()->route('login');
}
```

### Login Session Regeneration

On successful login, always regenerate the session to prevent fixation:

```php
// After successful authentication
$request->session()->regenerate();
```

This is already handled by Fortify/Laravel's default auth but ensure custom implementations include it.

---

## 5. Integration with Laravel Fortify

### Multi-Guard Fortify Configuration

Your setup likely needs dynamic Fortify configuration. In `FortifyServiceProvider`:

```php
public function register(): void
{
    $this->configureFortifyForRequest(request());
}

private function configureFortifyForRequest(Request $request): void
{
    if ($request->is('agent/*')) {
        config([
            'fortify.guard' => 'agent',
            'fortify.home' => '/agent/dashboard',
            'fortify.prefix' => 'agent',
        ]);
    } elseif ($request->is('customer/*')) {
        config([
            'fortify.guard' => 'customer',
            'fortify.home' => '/customer/dashboard',
            'fortify.prefix' => 'customer',
        ]);
    } elseif ($request->is('crew/*')) {
        config([
            'fortify.guard' => 'crew',
            'fortify.home' => '/crew/dashboard',
            'fortify.prefix' => 'crew',
        ]);
    }
    // Default: web guard
}
```

### Custom Login/Logout Responses

Bind custom response classes for per-guard redirects:

```php
// app/Providers/FortifyServiceProvider.php
public function boot(): void
{
    $this->app->singleton(LoginResponse::class, MultiGuardLoginResponse::class);
    $this->app->singleton(LogoutResponse::class, MultiGuardLogoutResponse::class);
}
```

```php
// app/Http/Responses/MultiGuardLoginResponse.php
namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\Request;

class MultiGuardLoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $guard = config('fortify.guard', 'web');

        $intended = session()->pull('url.intended');

        // Validate intended URL matches guard
        $guardPrefix = match ($guard) {
            'agent' => 'agent/',
            'customer' => 'customer/',
            'crew' => 'crew/',
            default => '',
        };

        if ($intended && ($guardPrefix === '' || str_contains($intended, $guardPrefix))) {
            return redirect()->to($intended);
        }

        return redirect()->intended(config('fortify.home', '/dashboard'));
    }
}
```

---

## 6. Summary of Recommendations for Apex Negotiations

### Keep (Current Implementation is Good)
1. **Shared session cookie** - Correct for single-User-model architecture
2. **Database session driver** - Good for traceability and security
3. **Route-prefix-based redirectGuestsTo** - Proper Laravel 12 pattern
4. **Four guards with same model** - Appropriate for your user types

### Improve

1. **Fix type middleware to throw AuthenticationException for unauthenticated users**
   - Change `abort(403)` for unauthenticated to `throw new AuthenticationException`

2. **Add intended URL validation in login success handlers**
   - Prevent cross-guard redirect hijacking

3. **Configure Fortify dynamically per guard**
   - If using Fortify for auth, set guard/home/prefix per route context

4. **Create custom logout logic**
   - Either logout from specific guard only, or logout from all guards

5. **Consider custom 403 pages per guard**
   - Better UX with guard-specific error messages

### Implementation Priority

1. **High**: Fix middleware authentication vs authorization distinction
2. **Medium**: Add intended URL validation
3. **Low**: Custom 403 pages (nice to have, not critical)

---

## Sources

- [Laravel 12 Authentication Documentation](https://laravel.com/docs/12.x/authentication)
- [Multi-guard authentication with Laravel 12 - DEV Community](https://dev.to/preciousaang/multi-guard-authentication-with-laravel-12-1jg3)
- [Laravel Fortify Documentation](https://laravel.com/docs/12.x/fortify)
- [Multi-Guard Authentication with Laravel Fortify - Max Eckel](https://max-eckel.dev/posts/multi-guard-authentication-with-laravel-fortify)
- [How I Handled Multiple Auth Guards and Redirects in Laravel](https://iwasherefirst2.medium.com/how-i-handled-multiple-auth-guards-and-redirects-in-laravel-ae6443940ce4)
- [Database sessions with multi-guard - Laracasts](https://laracasts.com/discuss/channels/code-review/database-sessions-with-multi-guard)
- [Multi Auth logout problem - GitHub](https://github.com/laravel/framework/issues/16322)
- [Session issue during logout from one guard - Laracasts](https://laracasts.com/discuss/channels/laravel/session-issue-during-logout-from-one-guard-all-the-guards-gets-logged-out)
- [Laravel Error Handling Documentation](https://laravel.com/docs/12.x/errors)
- [Dynamic session cookie name in Laravel](https://vpalos.com/2021/09/28/dynamic-session-cookie-name-in-laravel/)
