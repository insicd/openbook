<?php

namespace App\Http\Controllers;

use App\Application\Queries\LocalSearchQuery;
use App\Http\Support\FederatedHandleParser;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Actors\RemoteActorResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Motore di ricerca interno, con due percorsi distinti:
 *
 * 1. Indirizzo federato (`utente@dominio`, `acct:...`, URL di profilo):
 *    risoluzione diretta locale o via WebFinger + {@see RemoteActorResolver},
 *    poi redirect al profilo (comportamento originale della Fase 4).
 * 2. Qualunque altra stringa: ricerca *locale* per parole chiave su persone,
 *    post, commenti e hashtag di questa istanza ({@see LocalSearchQuery}),
 *    senza mai interrogare server remoti.
 *
 * Se la query inizia con "#" e gli hashtag trovati sono esattamente uno,
 * si va direttamente alla pagina di quel tag (scelta implicita). Con piu'
 * hashtag restano i risultati di ricerca, cosi' l'utente puo' scegliere.
 *
 * Il form usa GET (`/cerca?q=...`) cosi' i risultati sono bookmarkabili e un
 * refresh non ripete un POST.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly RemoteActorResolver $resolver,
        private readonly LocalActorResolver $localActors,
        private readonly LocalSearchQuery $localSearch,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return view('search.index', [
                'query' => '',
                'results' => null,
            ]);
        }

        if (mb_strlen($query) < (int) config('openbook.search.min_length', 2)) {
            throw ValidationException::withMessages([
                'q' => __('openbook.search.errors.too_short', [
                    'min' => (int) config('openbook.search.min_length', 2),
                ]),
            ]);
        }

        $handle = $this->extractHandle($query);

        if ($handle !== null) {
            return $this->resolveHandle($handle);
        }

        $viewer = $request->user()?->actor;
        $results = $this->localSearch->search($query, $viewer);

        if ($this->shouldOpenSoleHashtag($query, $results['hashtags'])) {
            return redirect()->route('hashtags.show', $results['hashtags']->first()->name);
        }

        return view('search.index', [
            'query' => $query,
            'results' => $results,
        ]);
    }

    /**
     * Query esplicita da hashtag (`#…`): un solo tag trovato → apri quel tag.
     *
     * @param  \Illuminate\Support\Collection<int, Hashtag>  $hashtags
     */
    private function shouldOpenSoleHashtag(string $query, $hashtags): bool
    {
        if (! str_starts_with($query, '#')) {
            return false;
        }

        return $hashtags->count() === 1;
    }

    /**
     * @param  array{0: string, 1: string}  $handle
     */
    private function resolveHandle(array $handle): RedirectResponse
    {
        [$username, $domain] = $handle;

        if (strcasecmp($domain, (string) config('openbook.domain')) === 0) {
            $actor = $this->localActors->findByUsername($username);

            if ($actor === null) {
                throw ValidationException::withMessages([
                    'q' => __('openbook.search.errors.local_not_found'),
                ]);
            }

            return redirect()->to($actor->profileUrl());
        }

        $actor = $this->resolver->resolveByHandle($username.'@'.$domain);

        if ($actor === null) {
            throw ValidationException::withMessages([
                'q' => __('openbook.search.errors.remote_not_found'),
            ]);
        }

        return redirect()->route('actors.show', $actor);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function extractHandle(string $query): ?array
    {
        return FederatedHandleParser::parse($query);
    }
}
