<?php

namespace App\Federation\Support;

use Illuminate\Support\Carbon;

/**
 * Normalizza i timestamp ActivityPub (campo "published", ecc.) al timezone
 * dell'applicazione prima del persist Eloquent.
 *
 * {@see Carbon::parse()} conserva l'offset remoto (es. "+02:00" tipico di
 * Friendica / alcuni feed custom). Il cast "datetime" di Eloquent serializza
 * pero' l'orologio murale di quell'istanza Carbon senza convertirlo, e in
 * lettura lo interpreta come {@code APP_TIMEZONE} (di default UTC): il
 * risultato e' un "published_at" spostato nel futuro e in UI compare
 * "tra xx min/ore" al posto di "xx fa".
 */
final class ActivityPubTimestamp
{
    public static function parse(?string $value, ?Carbon $fallback = null): Carbon
    {
        if (! is_string($value) || $value === '') {
            return self::normalize($fallback ?? now());
        }

        return self::normalize(Carbon::parse($value));
    }

    /**
     * Porta un Carbon (eventualmente con offset remoto) al timezone app,
     * cosi' il cast Eloquent "datetime" persiste l'istante corretto.
     */
    public static function normalize(Carbon $value): Carbon
    {
        return $value->copy()->timezone((string) config('app.timezone', 'UTC'));
    }
}
