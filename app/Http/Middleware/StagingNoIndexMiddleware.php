<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StagingNoIndexMiddleware
{
    /**
     * Prevent search engines from indexing non-production environments via HTTP header
     * while keeping HTML <meta name="robots"> accurate for SEO parity verification.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (strtolower((string) config('app.env')) !== 'production') {
            if (!$response->headers->has('X-Robots-Tag')) {
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }
        }

        return $response;
    }
}
