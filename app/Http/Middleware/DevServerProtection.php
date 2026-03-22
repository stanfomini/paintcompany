<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevServerProtection
{
    /**
     * Protect the dev server from public access and search engine indexing.
     *
     * On non-production domains (e.g. apexnegotiations.com):
     * - Serves a "disallow all" robots.txt
     * - Requires HTTP Basic Auth for all other requests
     * - Adds X-Robots-Tag: noindex header to all responses
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningUnitTests() || ! $this->isDevServer($request)) {
            return $next($request);
        }

        // Serve a blocking robots.txt on dev (overrides the static file)
        if ($request->is('robots.txt')) {
            return response("User-agent: *\nDisallow: /\n", 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

        // Allow webhooks and API endpoints without auth (Twilio, Mailgun, etc.)
        if ($request->is('webhook/*', 'webhooks/*', 'api/*', 'broadcasting/*')) {
            $response = $next($request);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        // Require HTTP Basic Auth
        $user = config('app.dev_auth_user', 'dev');
        $pass = config('app.dev_auth_pass', '');

        if (! $pass) {
            // No password configured — skip auth but still add noindex
            $response = $next($request);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $authUser = $request->getUser();
        $authPass = $request->getPassword();

        if ($authUser !== $user || $authPass !== $pass) {
            return response('Unauthorized.', 401, [
                'WWW-Authenticate' => 'Basic realm="Dev Server"',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function isDevServer(Request $request): bool
    {
        $host = $request->getHost();
        $productionDomain = 'paintingmadeeasydenver.com';

        return $host !== $productionDomain && $host !== 'www.'.$productionDomain;
    }
}
