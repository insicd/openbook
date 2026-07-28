<?php

namespace App\Http\Controllers\Federation;

use App\Domain\Accounts\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Implementa /.well-known/webfinger per la scoperta degli account locali
 * (sezione 16 del design). Risolve soltanto utenti di questa istanza: la
 * ricerca federata di attori remoti e' Fase 4, le community sono Fase 5.
 */
final class WebFingerController extends Controller
{
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

        $user = User::query()->where('username', $username)->with('actor')->first();

        if ($user === null || $user->actor === null || ! $user->actor->isActive()) {
            throw new NotFoundHttpException;
        }

        $actor = $user->actor;

        return response()->json([
            'subject' => 'acct:'.$actor->handle(),
            'aliases' => [
                $actor->uri,
                url('/users/'.$actor->preferred_username),
            ],
            'links' => [
                [
                    'rel' => 'self',
                    'type' => 'application/activity+json',
                    'href' => $actor->uri,
                ],
                [
                    'rel' => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $actor->uri,
                ],
            ],
        ], 200, ['Content-Type' => 'application/jrd+json; charset=utf-8']);
    }

    /**
     * Accetta sia "acct:utente@dominio" sia l'URL canonico dell'Actor,
     * restituendo lo username locale soltanto se il dominio corrisponde a
     * questa istanza.
     */
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
        }

        return null;
    }
}
