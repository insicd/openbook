<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateRest
{
    public function handle(Request $request, Closure $next): Response
    {
        // REST accepts Bearer credentials only, never the website's session cookie.
        abort_unless($request->bearerToken(), 401, '', ['WWW-Authenticate' => 'Bearer']);
        $user = Auth::guard('rest')->user();
        abort_unless($user !== null, 401, '', ['WWW-Authenticate' => 'Bearer']);
        abort_unless($user->isActive() && $user->actor?->isActive(), 403);
        abort_unless($user->tokenCan('api'), 403);
        Auth::shouldUse('rest');

        return $next($request);
    }
}
