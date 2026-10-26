<?php

namespace App\Http\Middleware;

use App\Infrastructure\Installation\InstallationLock;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRestOAuthRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment('local', 'testing'), 404);
        abort_unless(InstallationLock::isInstalled(), 503);

        if ($request->routeIs('passport.token')) {
            if (! in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
                return response()->json(['error' => 'unsupported_grant_type'], 400, ['Cache-Control' => 'no-store']);
            }
        } elseif ($request->routeIs('passport.authorizations.*')) {
            $user = $request->user('web');
            abort_if($user !== null && (! $user->isActive() || ! $user->actor?->isActive()), 403);
            if ($request->isMethod('GET') && ($request->query('response_type') !== 'code'
                || $request->query('code_challenge_method') !== 'S256'
                || ! is_string($request->query('code_challenge')) || $request->query('code_challenge') === '')) {
                return response()->json([
                    'error' => 'invalid_request',
                    'error_description' => 'Authorization Code with PKCE S256 is required.',
                ], 400, ['Cache-Control' => 'no-store']);
            }
        }

        $response = $request->routeIs('passport.token')
            ? Passport::token()->getConnection()->transaction(fn () => $next($request))
            : $next($request);
        if (! $request->routeIs('oauth.metadata')) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
