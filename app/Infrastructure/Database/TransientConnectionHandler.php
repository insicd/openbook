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
 * Un semplice reload di solito basta (nuova richiesta = nuova chance di
 * rientrare nel rate limit). Qui lo facciamo automaticamente una sola volta
 * per le GET, cosi' l'utente non vede lo stack SQL; se fallisce di nuovo,
 * mostriamo una pagina 503 amichevole invece dell'eccezione grezza.
 *
 * La mitigazione "a monte" resta comunque abilitare le connessioni PDO
 * persistenti (`DB_PERSISTENT=true`), come raccomandato dallo stesso
 * hosting: riduce drasticamente il numero di nuove connessioni al secondo.
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

        return response()->view('errors.database-busy', [
            'retryUrl' => $request->isMethod('GET') ? $request->fullUrl() : null,
        ], 503);
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
