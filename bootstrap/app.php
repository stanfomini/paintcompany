<?php

use App\Exceptions\UserTypeMismatchException;
use App\Http\Middleware\EnsureUserIsAgent;
use App\Http\Middleware\EnsureUserIsCrew;
use App\Http\Middleware\EnsureUserIsCustomer;
use App\Http\Middleware\EnsureUserIsInternal;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix('agent')
                ->name('agent.')
                ->group(base_path('routes/agent.php'));

            Route::middleware('web')
                ->prefix('customer')
                ->name('customer.')
                ->group(base_path('routes/customer.php'));

            Route::middleware('web')
                ->prefix('crew')
                ->name('crew.')
                ->group(base_path('routes/crew.php'));
        },
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth:web,agent,customer,crew']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.internal' => EnsureUserIsInternal::class,
            'auth.agent' => EnsureUserIsAgent::class,
            'auth.customer' => EnsureUserIsCustomer::class,
            'auth.crew' => EnsureUserIsCrew::class,
            // Spatie Permission middleware
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Redirect authenticated users away from guest-only routes (like login pages)
        $middleware->redirectUsersTo(function ($request) {
            // Check which guard the user is authenticated with
            foreach (['customer', 'agent', 'crew', 'web'] as $guard) {
                if (auth()->guard($guard)->check()) {
                    $user = auth()->guard($guard)->user();

                    // Determine which guard this user should be on based on their type
                    $correctGuard = match ($user->type) {
                        'internal' => 'web',
                        'agent' => 'agent',
                        'customer' => 'customer',
                        'crew' => 'crew',
                        default => 'web',
                    };

                    // If user is on the wrong guard (e.g. customer logged in via admin form),
                    // log them out to break any redirect loop and send to correct login
                    if ($guard !== $correctGuard) {
                        auth()->guard($guard)->logout();

                        return route(match ($user->type) {
                            'agent' => 'agent.login',
                            'customer' => 'customer.login',
                            'crew' => 'crew.login',
                            default => 'login',
                        });
                    }

                    return match ($user->type) {
                        'internal' => route('dashboard'),
                        'agent' => route('agent.dashboard'),
                        'customer' => route('customer.dashboard'),
                        'crew' => route('crew.dashboard'),
                        default => route('dashboard'),
                    };
                }
            }

            return route('dashboard');
        });

        $middleware->redirectGuestsTo(function ($request) {
            // First check if user is logged in with any guard
            foreach (['web', 'agent', 'customer', 'crew'] as $guard) {
                if (auth()->guard($guard)->check()) {
                    $user = auth()->guard($guard)->user();

                    // Determine which guard this user should be on
                    $correctGuard = match ($user->type) {
                        'internal' => 'web',
                        'agent' => 'agent',
                        'customer' => 'customer',
                        'crew' => 'crew',
                        default => 'web',
                    };

                    // If user is on the wrong guard, log them out to prevent redirect loops
                    if ($guard !== $correctGuard) {
                        auth()->guard($guard)->logout();
                        session()->flash('warning', 'Please log in with the correct login page for your account.');

                        return route(match ($user->type) {
                            'agent' => 'agent.login',
                            'customer' => 'customer.login',
                            'crew' => 'crew.login',
                            default => 'login',
                        });
                    }

                    // User is on the correct guard but wrong area - redirect to their dashboard
                    $dashboardRoute = match ($user->type) {
                        'internal' => 'dashboard',
                        'agent' => 'agent.dashboard',
                        'customer' => 'customer.dashboard',
                        'crew' => 'crew.dashboard',
                        default => null,
                    };

                    if ($dashboardRoute) {
                        session()->flash('warning', 'You do not have access to that area.');

                        return route($dashboardRoute);
                    }
                }
            }

            // Not logged in with any guard - redirect to appropriate login based on URL
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // UserTypeMismatchException has its own render() method that returns a redirect
        $exceptions->render(function (UserTypeMismatchException $e, Request $request) {
            return $e->render($request);
        });

        // Handle AuthenticationException - when user is not authenticated with the expected guard
        // but might be authenticated with a different guard
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return null; // Let Laravel handle JSON requests
            }

            // Check if user is logged in with any guard
            foreach (['web', 'agent', 'customer', 'crew'] as $guard) {
                if (auth()->guard($guard)->check()) {
                    $user = auth()->guard($guard)->user();

                    // Determine which guard this user should be on
                    $correctGuard = match ($user->type) {
                        'internal' => 'web',
                        'agent' => 'agent',
                        'customer' => 'customer',
                        'crew' => 'crew',
                        default => 'web',
                    };

                    // If user is on the wrong guard (e.g. customer on web guard),
                    // log them out to prevent redirect loops
                    if ($guard !== $correctGuard) {
                        auth()->guard($guard)->logout();
                        session()->flash('warning', 'Please log in with the correct login page for your account.');

                        $loginRoute = match ($user->type) {
                            'agent' => 'agent.login',
                            'customer' => 'customer.login',
                            'crew' => 'crew.login',
                            default => 'login',
                        };

                        return new \Illuminate\Http\RedirectResponse(route($loginRoute));
                    }

                    // User is on correct guard but trying to access wrong area
                    $dashboardRoute = match ($user->type) {
                        'internal' => 'dashboard',
                        'agent' => 'agent.dashboard',
                        'customer' => 'customer.dashboard',
                        'crew' => 'crew.dashboard',
                        default => null,
                    };

                    if ($dashboardRoute) {
                        session()->flash('warning', 'You do not have access to that area.');

                        return new \Illuminate\Http\RedirectResponse(route($dashboardRoute));
                    }
                }
            }

            // Not logged in with any guard - use default behavior (redirect to login)
            return null;
        });

        // Handle generic 403 errors from sources other than UserTypeMismatchException
        // This handles TYPE mismatches (wrong user type for an area) but NOT resource-level authorization
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 403) {
                return null; // Let Laravel handle non-403 errors
            }

            // Don't redirect for JSON/API requests
            if ($request->expectsJson()) {
                return null;
            }

            // Don't redirect for Livewire requests - these go to /livewire/update or /livewire-unit-test-endpoint/*
            // and we can't determine the original context from the URL
            if ($request->hasHeader('X-Livewire') || $request->is('livewire/*') || $request->is('livewire-unit-test-endpoint/*')) {
                return null;
            }

            // Determine the expected guard based on URL prefix
            $expectedGuard = match (true) {
                $request->is('agent/*') => 'agent',
                $request->is('customer/*') => 'customer',
                $request->is('crew/*') => 'crew',
                default => 'web',
            };

            // Check if user is logged in with ANY guard
            foreach (['web', 'agent', 'customer', 'crew'] as $guard) {
                if (auth()->guard($guard)->check()) {
                    $user = auth()->guard($guard)->user();

                    // If user is authenticated with the CORRECT guard for this URL, this is a
                    // resource-level authorization error (not a type mismatch) - let 403 propagate
                    if ($guard === $expectedGuard) {
                        return null; // Let the 403 response through
                    }

                    // Type mismatch: User is logged in but with wrong guard for this area
                    // Redirect to their correct dashboard with a warning
                    $dashboardRoute = match ($user->type) {
                        'internal' => 'dashboard',
                        'agent' => 'agent.dashboard',
                        'customer' => 'customer.dashboard',
                        'crew' => 'crew.dashboard',
                        default => null,
                    };

                    if ($dashboardRoute) {
                        // Use explicit RedirectResponse to avoid Livewire's Redirector
                        session()->flash('warning', 'You do not have access to that area.');

                        return new \Illuminate\Http\RedirectResponse(route($dashboardRoute));
                    }
                }
            }

            // User is not authenticated with any guard
            // Let the 403 propagate - if they need to be redirected to login,
            // that's handled by the auth middleware, not a 403 exception
            // This ensures legitimate 403s (like signed URL failures) work correctly
            return null;
        });
    })->create();
