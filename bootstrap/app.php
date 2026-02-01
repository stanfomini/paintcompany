<?php

use App\Http\Middleware\EnsureUserIsAgent;
use App\Http\Middleware\EnsureUserIsCustomer;
use App\Http\Middleware\EnsureUserIsInternal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.internal' => EnsureUserIsInternal::class,
            'auth.agent' => EnsureUserIsAgent::class,
            'auth.customer' => EnsureUserIsCustomer::class,
        ]);

        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('agent/*')) {
                return route('agent.login');
            }

            if ($request->is('customer/*')) {
                return route('customer.login');
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
