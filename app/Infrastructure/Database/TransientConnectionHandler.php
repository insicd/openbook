<?php

namespace App\Infrastructure\Database;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * Mitiga gli errori transitori di connessione MySQL/MariaDB tipici di certi
 * hosting condivisi (Hostinger in particolare): quando si supera il limite di
 * nuove connessioni al secondo, il server risponde con
 * `SQLSTATE[HY000] [2002] Operation not permitted`.
 *
 * Per le GET: un redirect automatico una sola volta (equivalente a un reload
 * silenzioso). Se fallisce di nuovo, invece di una 503 "servizio non
 * disponibile" mostriamo una pagina di caricamento che ripete da sola la
 * richiesta con backoff, cosi' all'utente sembra latenza e non un outage.
 *
 * POST e simili non si ripetono da soli, per non duplicare azioni.
 *
 * La mitigazione "a monte" resta abilitare le connessioni PDO persistenti
 * (`DB_PERSISTENT=true`): riduce drasticamente le nuove connessioni/secondo.
 */
final class TransientConnectionHandler
{
    public const RETRY_FLASH_KEY = 'ob_db_connection_retried';

    public function isTransient(Throwable $e): bool
    {
        if ($e instanceof QueryException) {
            return $this->matches($e->getCode(), $e->getMessage(), $e->errorInfo[1] ?? null);
        }

        if ($e instanceof PDOException) {
            return $this->matches($e->getCode(), $e->getMessage(), $e->errorInfo[1] ?? null);
        }

        $previous = $e->getPrevious();

        return $previous instanceof Throwable && $this->isTransient($previous);
    }

    public function handle(Request $request): RedirectResponse|Response
    {
        Log::warning('database.transient_connection', [
            'path' => $request->path(),
            'method' => $request->method(),
            'already_retried' => $request->hasSession() && $request->session()->get(self::RETRY_FLASH_KEY),
        ]);

        $this->purgeConnection();

        // Solo GET: un redirect ripete la stessa richiesta in modo sicuro
        // (equivalente al "reload" che l'utente farebbe a mano). POST e
        // simili non si ripetono da soli, per non duplicare azioni.
        if (
            $request->isMethod('GET')
            && $request->hasSession()
            && ! $request->session()->get(self::RETRY_FLASH_KEY)
        ) {
            return redirect()
                ->to($request->fullUrl())
                ->with(self::RETRY_FLASH_KEY, true);
        }

        $retryUrl = $request->isMethod('GET') ? $request->fullUrl() : null;

        return response()
            ->view('errors.database-busy', [
                'retryUrl' => $retryUrl,
            ], 503)
            ->header('Retry-After', '2');
    }

    private function matches(string|int $code, string $message, mixed $driverCode): bool
    {
        $code = (string) $code;
        $driverCode = $driverCode !== null ? (string) $driverCode : '';

        if ($code === '2002' || $driverCode === '2002' || str_contains($code, '2002')) {
            return true;
        }

        return str_contains($message, 'Operation not permitted')
            && (str_contains($message, '2002') || str_contains($message, 'HY000'));
    }

    private function purgeConnection(): void
    {
        try {
            DB::purge();
        } catch (Throwable) {
            // La connessione e' gia' morta: ignoriamo eventuali errori di chiusura.
        }
    }
}
