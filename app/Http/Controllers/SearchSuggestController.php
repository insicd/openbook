<?php

namespace App\Http\Controllers;

use App\Application\Queries\LocalSearchQuery;
use App\Application\Queries\MentionSuggestQuery;
use App\Domain\Accounts\User;
use App\Domain\Posts\Hashtag;
use App\Federation\Actors\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autocomplete della ricerca (navbar e pagina /cerca): persone locali
 * discoverable, Person remoti gia' in cache, e hashtag. Nessuna chiamata
 * WebFinger: la risoluzione federata resta sul submit della ricerca.
 */
class SearchSuggestController extends Controller
{
    public function __construct(
        private readonly LocalSearchQuery $localSearch,
        private readonly MentionSuggestQuery $mentionSuggest,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $viewer = $request->user()?->actor;
        $limit = (int) config('openbook.search.suggest_limit', 8);
        $peopleLimit = (int) ceil($limit * 0.6);
        $hashtagLimit = max(1, $limit - $peopleLimit);

        $local = $this->localSearch->suggest($term, $peopleLimit, $hashtagLimit);

        $suggestions = $local['people']
            ->map(fn (User $user) => $this->personFromUser($user))
            ->values()
            ->all();

        if (! str_starts_with($term, '#')) {
            $knownLocalUsernames = $local['people']
                ->map(fn (User $user) => mb_strtolower($user->username))
                ->all();

            $remoteSlots = max(0, $peopleLimit - count($suggestions));

            if ($remoteSlots > 0) {
                $remotes = $this->mentionSuggest
                    ->forPrefix($term, $viewer, $remoteSlots + count($knownLocalUsernames))
                    ->filter(fn (Actor $actor) => ! $actor->isLocal())
                    ->take($remoteSlots);

                foreach ($remotes as $actor) {
                    $suggestions[] = $this->personFromActor($actor);
                }
            }
        }

        foreach ($local['hashtags'] as $hashtag) {
            $suggestions[] = $this->hashtagSuggestion($hashtag);
        }

        return response()->json([
            'suggestions' => array_values($suggestions),
        ]);
    }

    /**
     * @return array{type: string, url: string, handle: string, display_name: string, avatar_url: ?string, is_local: bool}
     */
    private function personFromUser(User $user): array
    {
        $actor = $user->actor;

        return [
            'type' => 'person',
            'url' => route('profile.show', $user->username),
            'handle' => $user->username,
            'display_name' => $actor?->displayName() ?: ($user->profile?->display_name ?: $user->username),
            'avatar_url' => $actor?->avatarUrl(),
            'is_local' => true,
        ];
    }

    /**
     * @return array{type: string, url: string, handle: string, display_name: string, avatar_url: ?string, is_local: bool}
     */
    private function personFromActor(Actor $actor): array
    {
        return [
            'type' => 'person',
            'url' => $actor->profileUrl(),
            'handle' => $actor->handle(),
            'display_name' => $actor->displayName(),
            'avatar_url' => $actor->avatarUrl(),
            'is_local' => false,
        ];
    }

    /**
     * @return array{type: string, url: string, handle: string, display_name: string, avatar_url: null, is_local: bool}
     */
    private function hashtagSuggestion(Hashtag $hashtag): array
    {
        return [
            'type' => 'hashtag',
            'url' => route('hashtags.show', $hashtag->name),
            'handle' => $hashtag->name,
            'display_name' => '#'.$hashtag->name,
            'avatar_url' => null,
            'is_local' => true,
        ];
    }
}
