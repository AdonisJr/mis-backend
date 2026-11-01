<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    private const ACCEPT_HEADER = 'Accept';

    private const JSON_MEDIA_TYPE = 'application/json';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // clients like the RFID firmware send no Accept header; without JSON the auth guard tries a redirect to a non-existent login route
        $request->headers->set(self::ACCEPT_HEADER, self::JSON_MEDIA_TYPE);

        return $next($request);
    }
}
