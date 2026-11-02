<?php

namespace App\Http\Middleware;

use App\Infrastructure\Installation\InstallationLock;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class RestPersonRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            abort_unless(InstallationLock::isInstalled(), 503);

            $response = $next($request);
            if ($response->getStatusCode() < 400) {
                return $response;
            }
            if ($response->headers->get('Content-Type') === 'application/problem+json') {
                return $response;
            }

            $status = $response->getStatusCode();
            $headers = array_filter([
                'Retry-After' => $response->headers->get('Retry-After'),
                'WWW-Authenticate' => $response->headers->get('WWW-Authenticate'),
            ], static fn (?string $value): bool => $value !== null);
        } catch (Throwable $exception) {
            $status = match (true) {
                $exception instanceof AuthorizationException => $exception->status() ?? 403,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
            if ($status >= 500) {
                report($exception);
            }

        }

        return response()->json([
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
        ], $status, array_merge($headers, [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store',
        ]));
    }
}
