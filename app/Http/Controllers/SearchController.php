<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\User;
use App\Federation\Actors\RemoteActorResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ricerca federata (sezione "ricerca remota" della Fase 4): accetta un
 * indirizzo "utente@dominio" (con o senza "@" iniziale, o un URL di profilo)
 * e, se non corrisponde a un account locale, lo risolve tramite WebFinger
 * sul dominio remoto. Volutamente minimale: nessun indice di ricerca
 * full-text, nessuna ricerca per parole chiave nei contenuti, solo
 * risoluzione diretta di un indirizzo federato.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly RemoteActorResolver $resolver,
    ) {}

    public function create(): View
    {
        return view('search.index');
    }

    public function search(Request $request): RedirectResponse
    {
        $query = trim((string) $request->input('q', ''));

        if ($query === '') {
            throw ValidationException::withMessages(['q' => 'Inserisci un indirizzo nella forma utente@dominio.']);
        }

        $handle = $this->extractHandle($query);

        if ($handle === null) {
            throw ValidationException::withMessages(['q' => 'Formato non riconosciuto: usa "utente@dominio" oppure l\'URL del profilo.']);
        }

        [$username, $domain] = $handle;

        if (strcasecmp($domain, (string) config('openbook.domain')) === 0) {
            $user = User::query()->where('username', mb_strtolower($username))->first();

            if ($user === null) {
                throw ValidationException::withMessages(['q' => 'Nessun account locale trovato con questo indirizzo.']);
            }

            return redirect()->route('profile.show', $user->username);
        }

        $actor = $this->resolver->resolveByHandle($username.'@'.$domain);

        if ($actor === null) {
            throw ValidationException::withMessages(['q' => 'Nessun account trovato a questo indirizzo, o il server remoto non risponde.']);
        }

        return redirect()->route('actors.show', $actor);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function extractHandle(string $query): ?array
    {
        $query = ltrim($query, '@');

        if (str_starts_with($query, 'acct:')) {
            $query = substr($query, 5);
        }

        if (preg_match('#^https?://#i', $query) === 1) {
            $host = parse_url($query, PHP_URL_HOST);
            $path = trim((string) parse_url($query, PHP_URL_PATH), '/');
            $segments = explode('/', $path);
            $lastSegment = ltrim((string) end($segments), '@');

            if (! is_string($host) || $host === '' || $lastSegment === '') {
                return null;
            }

            return [$lastSegment, $host];
        }

        $parts = explode('@', $query, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return [$parts[0], $parts[1]];
    }
}
