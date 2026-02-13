<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var array<string> */
    public const SUPPORTED_LOCALES = ['en', 'es'];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale', config('app.locale', 'en'));

        if (in_array($locale, self::SUPPORTED_LOCALES)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
