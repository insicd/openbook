<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sezioni del pannello riservate agli amministratori (impostazioni istanza,
 * promozione moderatori, ecc.).
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canAdminister()) {
            abort(403);
        }

        return $next($request);
    }
}
