<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public const CHANGE_REQUIRED_MESSAGE = 'Change your password first.';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json(['message' => self::CHANGE_REQUIRED_MESSAGE], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
