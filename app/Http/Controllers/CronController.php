<?php

namespace App\Http\Controllers;

use App\Infrastructure\Database\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Endpoint web opzionale per eseguire "openbook:cron" tramite una richiesta
 * HTTP autenticata da token, per gli hosting privi di cron reale o di
 * accesso alla riga di comando (sezione "Cron via web" del design). Disabilitato
 * di default: va abilitato esplicitamente con "OPENBOOK_WEB_CRON_ENABLED=true".
 *
 * Il token e' confrontato con {@see hash_equals()} per evitare timing attack,
 * e ogni esecuzione riuscita aggiorna un timestamp che impedisce chiamate
 * piu' frequenti dell'intervallo minimo configurato: senza questo limite,
 * chiunque conoscesse l'URL potrebbe forzare esecuzioni concorrenti e
 * sovraccaricare inutilmente l'hosting.
 */
final class CronController extends Controller
{
    private const LAST_RUN_SETTING_KEY = 'web_cron_last_run_at';

    public function run(Request $request): JsonResponse
    {
        if (! (bool) config('openbook.web_cron.enabled')) {
            abort(404);
        }

        $expectedToken = SystemSetting::get('cron_token') ?: config('openbook.web_cron.token');
        $providedToken = (string) $request->query('token', '');

        if (blank($expectedToken) || $providedToken === '' || ! hash_equals((string) $expectedToken, $providedToken)) {
            abort(403);
        }

        $minInterval = max(0, (int) config('openbook.web_cron.min_interval_seconds', 55));
        $lastRunAt = SystemSetting::get(self::LAST_RUN_SETTING_KEY);

        if ($lastRunAt !== null && Carbon::parse($lastRunAt)->addSeconds($minInterval)->isFuture()) {
            return response()->json(['status' => 'skipped', 'reason' => 'intervallo minimo non ancora trascorso'], 429);
        }

        SystemSetting::put(self::LAST_RUN_SETTING_KEY, now()->toIso8601String());

        Artisan::call('openbook:cron');

        return response()->json(['status' => 'ok']);
    }
}
