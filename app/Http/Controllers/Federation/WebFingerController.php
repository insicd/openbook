<?php

namespace App\Http\Controllers\Federation;

use App\Federation\Actors\LocalActorResolver;
use App\Federation\Actors\LocalActorUrls;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Implementa /.well-known/webfinger per la scoperta degli Actor locali
 * (Person e Group / community).
 */
final class WebFingerController extends Controller
{
    public function __construct(
        private readonly LocalActorResolver $localActors,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $resource = (string) $request->query('resource', '');

        if ($resource === '') {
            throw new NotFoundHttpException;
        }

        $username = $this->extractLocalUsername($resource);

        if ($username === null) {
            throw new NotFoundHttpException;
        }

        $actor = $this->localActors->findByUsername($username);

        if ($actor === null || ! $actor->isActive()) {
            throw new NotFoundHttpException;
        }

        $urls = LocalActorUrls::forUsername($actor->preferred_username, $actor->isGroup());

        return response()->json([
            'subject' => 'acct:'.$actor->handle(),
            'aliases' => array_values(array_unique([
                $urls['uri'],
                $urls['profile'],
                $actor->uri,
            ])),
            'links' => [
                [
                    'rel' => 'self',
                    'type' => 'application/activity+json',
                    'href' => $urls['uri'],
                ],
                [
                    'rel' => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $urls['profile'],
                ],
            ],
        ], 200, ['Content-Type' => 'application/jrd+json; charset=utf-8']);
    }

    private function extractLocalUsername(string $resource): ?string
    {
        $domain = (string) config('openbook.domain');

        if (str_starts_with($resource, 'acct:')) {
            $address = substr($resource, 5);
            $parts = explode('@', $address, 2);

            if (count($parts) !== 2 || mb_strtolower($parts[1]) !== mb_strtolower($domain)) {
                return null;
            }

            return mb_strtolower($parts[0]);
        }

        if (str_starts_with($resource, 'http://') || str_starts_with($resource, 'https://')) {
            $host = parse_url($resource, PHP_URL_HOST);

            if (! is_string($host) || mb_strtolower($host) !== mb_strtolower($domain)) {
                return null;
            }

            $path = (string) parse_url($resource, PHP_URL_PATH);

            if (preg_match('#^/@([A-Za-z0-9_]+)$#', $path, $matches) === 1) {
                return mb_strtolower($matches[1]);
            }

            if (preg_match('#^/users/([A-Za-z0-9_]+)$#', $path, $matches) === 1) {
                return mb_strtolower($matches[1]);
            }

            if (preg_match('#^/c/([A-Za-z0-9_]+)$#', $path, $matches) === 1) {
                return mb_strtolower($matches[1]);
            }
        }

        return null;
    }
}
