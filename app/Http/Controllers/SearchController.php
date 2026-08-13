<?php

namespace App\Http\Controllers;

use App\Application\Queries\LocalSearchQuery;
use App\Domain\Feeds\FeedActorRegistrar;
use App\Domain\Feeds\FeedDiscoverer;
use App\Domain\Feeds\FeedImporter;
use App\Http\Support\FederatedHandleParser;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Actors\RemoteActorResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Motore di ricerca interno, con percorsi distinti:
 *
 * 1. URL http(s): prova Actor ActivityPub per URI, altrimenti discovery
 *    di un feed RSS/Atom (stile Friendica) e redirect al profilo "feed".
 * 2. Indirizzo federato (`utente@dominio`, `acct:...`, URL profilo AP):
 *    risoluzione locale o via WebFinger + {@see RemoteActorResolver}.
 * 3. Parola chiave: ricerca locale ({@see LocalSearchQuery}).
 *
 * Se la query inizia con "#" e gli hashtag trovati sono esattamente uno,
 * si va direttamente alla pagina di quel tag.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly RemoteActorResolver $resolver,
        private readonly LocalActorResolver $localActors,
        private readonly LocalSearchQuery $localSearch,
        private readonly FeedDiscoverer $feedDiscoverer,
        private readonly FeedActorRegistrar $feedRegistrar,
        private readonly FeedImporter $feedImporter,
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

        if ($this->looksLikeHttpUrl($query)) {
            return $this->resolveHttpUrl($query);
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

    private function looksLikeHttpUrl(string $query): bool
    {
        return preg_match('#^https?://#i', $query) === 1;
    }

    private function resolveHttpUrl(string $url): RedirectResponse
    {
        try {
            $actor = $this->resolver->resolveByUri($url);

            if ($actor !== null) {
                return redirect()->to($actor->profileUrl());
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $discovered = $this->feedDiscoverer->discover($url);
            $feedActor = $this->feedRegistrar->upsertFromDiscovered($discovered);
            $this->feedImporter->import(
                $feedActor,
                $discovered->body,
                (int) config('openbook.feeds.import_limit', 40),
            );

            return redirect()->to($feedActor->profileUrl());
        } catch (RuntimeException $feedException) {
            $handle = FederatedHandleParser::parse($url);

            if ($handle !== null) {
                return $this->resolveHandle($handle);
            }

            throw ValidationException::withMessages([
                'q' => $feedException->getMessage() !== ''
                    ? $feedException->getMessage()
                    : __('openbook.search.errors.feed_not_found'),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $handle = FederatedHandleParser::parse($url);

            if ($handle !== null) {
                return $this->resolveHandle($handle);
            }

            throw ValidationException::withMessages([
                'q' => __('openbook.search.errors.feed_not_found'),
            ]);
        }
    }

    /**
     * Query esplicita da hashtag (`#…`): un solo tag trovato → apri quel tag.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Domain\Posts\Hashtag>  $hashtags
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
