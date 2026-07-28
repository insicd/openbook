<?php

namespace App\Http\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Negoziazione del contenuto tra la rappresentazione HTML e quella
 * ActivityPub sullo stesso URL canonico (sezione 8 del design): un client che
 * chiede "Accept: application/activity+json" o "application/ld+json" riceve
 * il documento ActivityStreams, chiunque altro la pagina HTML abituale.
 */
final class ActivityPubNegotiation
{
    private const MEDIA_TYPES = [
        'application/activity+json',
        'application/ld+json',
    ];

    public static function wantsActivityPub(Request $request): bool
    {
        $accept = (string) $request->header('Accept', '');

        foreach (self::MEDIA_TYPES as $type) {
            if (str_contains($accept, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function response(array $document, int $status = 200): JsonResponse
    {
        return response()->json($document, $status, [
            'Content-Type' => 'application/activity+json; charset=utf-8',
        ]);
    }
}
