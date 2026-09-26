<?php

namespace App\Http\Controllers;

use App\Application\Queries\LocalSearchQuery;
use App\Application\Queries\PeopleSearchQuery;
use App\Domain\Feeds\FeedActorRegistrar;
use App\Domain\Feeds\FeedDiscoverer;
use App\Domain\Feeds\FeedImporter;
use App\Domain\Posts\Hashtag;
use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Events\RemoteEventUrlResolver;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Http\Support\FederatedHandleParser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Motore di ricerca interno, con percorsi distinti:
 *
 * 1. URL http(s): prima il Fediverso (documento Actor, poi WebFinger dal
 *    path tipo `/@utente`, poi link HTML `application/activity+json`);
 *    solo se non e' un profilo AP si passa al feed RSS/Atom (Friendica).
 * 2. Indirizzo federato (`utente@dominio`, `acct:...`): risoluzione locale
 *    o via WebFinger + {@see RemoteActorResolver}.
 * 3. Parola chiave: persone note e contenuti locali.
 *
 * Se la query inizia con "#" e gli hashtag trovati sono esattamente uno,
 * si va direttamente alla pagina di quel tag.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly RemoteActorResolver $resolver,
        private readonly RemoteEventUrlResolver $eventResolver,
        private readonly RemoteNoteDocumentFetcher $activityPubDocuments,
        private readonly LocalActorResolver $localActors,
        private readonly LocalSearchQuery $localSearch,
        private readonly PeopleSearchQuery $peopleSearch,
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
            return $this->resolveHttpUrl($query, $request->user()?->actor);
        }

        $handle = $this->extractHandle($query);

        if ($handle !== null) {
            return $this->resolveHandle($handle);
        }

        $viewer = $request->user()?->actor;
        $results = $this->localSearch->search($query, $viewer);
        $results['people'] = $this->peopleSearch->search(
            $query,
            (int) config('openbook.search.per_section', 10),
            (int) config('openbook.search.min_length', 2),
        );

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

    private function resolveHttpUrl(string $url, ?Actor $viewer): RedirectResponse
    {
        $document = $this->activityPubDocuments->fetchDocument($url, $viewer);
        $actor = $this->resolver->resolveFetchedDocument($document, $url);

        if ($actor !== null) {
            return redirect()->to($actor->profileUrl());
        }

        $event = $this->eventResolver->resolveFetchedDocument($document, $viewer);

        if ($event !== null) {
            return redirect()->route('events.show', $event);
        }

        $actor = $this->resolveActivityPubFromUrl($url);

        if ($actor !== null) {
            return redirect()->to($actor->profileUrl());
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
            throw ValidationException::withMessages([
                'q' => $feedException->getMessage() !== ''
                    ? $feedException->getMessage()
                    : __('openbook.search.errors.feed_not_found'),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'q' => __('openbook.search.errors.feed_not_found'),
            ]);
        }
    }

    /**
     * Fallback per URL HTML di profili Mastodon/Lemmy/ecc.: prova il loro
     * handle oppure il link alternate ActivityPub prima del feed RSS.
     */
    private function resolveActivityPubFromUrl(string $url): ?Actor
    {
        $handle = FederatedHandleParser::parse($url);

        if ($handle !== null && $this->shouldResolveHandleFromUrl($url, $handle)) {
            $actor = $this->lookupHandle($handle);

            if ($actor !== null) {
                return $actor;
            }

            try {
                $alternate = $this->feedDiscoverer->activityPubAlternateUrl($url);

                if ($alternate !== null && strcasecmp($alternate, $url) !== 0) {
                    return $this->resolver->resolveByUri($alternate);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return null;
    }

    /**
     * Evita WebFinger su path da file (`/feed.xml`): non e' un username.
     * I profili `/@utente` restano validi anche con un punto nel nome.
     *
     * @param  array{0: string, 1: string}  $handle
     */
    private function shouldResolveHandleFromUrl(string $url, array $handle): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_contains($path, '/@')) {
            return true;
        }

        return ! str_contains($handle[0], '.');
    }

    /**
     * @param  array{0: string, 1: string}  $handle
     */
    private function lookupHandle(array $handle): ?Actor
    {
        [$username, $domain] = $handle;

        if (strcasecmp($domain, (string) config('openbook.domain')) === 0) {
            return $this->localActors->findByUsername($username);
        }

        return $this->resolver->resolveByHandle($username.'@'.$domain);
    }

    /**
     * Query esplicita da hashtag (`#…`): un solo tag trovato → apri quel tag.
     *
     * @param  Collection<int, Hashtag>  $hashtags
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
        $domain = $handle[1];

        $actor = $this->lookupHandle($handle);

        if ($actor === null) {
            $isLocal = strcasecmp($domain, (string) config('openbook.domain')) === 0;

            throw ValidationException::withMessages([
                'q' => $isLocal
                    ? __('openbook.search.errors.local_not_found')
                    : __('openbook.search.errors.remote_not_found'),
            ]);
        }

        return redirect()->to($actor->profileUrl());
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function extractHandle(string $query): ?array
    {
        return FederatedHandleParser::parse($query);
    }
}
