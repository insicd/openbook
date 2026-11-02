<?php

namespace App\Http\Controllers;

use App\Application\Queries\LocalSearchQuery;
use App\Application\Queries\PeopleSearchQuery;
use App\Domain\Posts\Hashtag;
use App\Federation\Actors\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autocomplete della ricerca (navbar e pagina /cerca): profili gia' noti
 * all'istanza (persone discoverable, bot, feed RSS, community pubbliche)
 * e hashtag. Nessuna chiamata WebFinger: la risoluzione federata resta
 * sul submit della ricerca.
 */
class SearchSuggestController extends Controller
{
    public function __construct(
        private readonly LocalSearchQuery $localSearch,
        private readonly PeopleSearchQuery $peopleSearch,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $limit = (int) config('openbook.search.suggest_limit', 8);
        $peopleLimit = (int) ceil($limit * 0.6);
        $hashtagLimit = max(1, $limit - $peopleLimit);

        $suggestions = str_starts_with($term, '#')
            ? []
            : $this->peopleSearch
                ->search($term, $peopleLimit, (int) config('openbook.search.suggest_min_length', 2))
                ->sortBy(fn (Actor $actor) => match ($actor->type) {
                    Actor::TYPE_PERSON => 0,
                    Actor::TYPE_APPLICATION => 1,
                    Actor::TYPE_FEED => 2,
                    Actor::TYPE_GROUP => 3,
                    default => 4,
                })
                ->map(fn (Actor $actor) => $this->suggestionFromActor($actor))
                ->values()
                ->all();

        $hashtags = $this->localSearch->suggestHashtags(
            $term,
            str_starts_with($term, '#') ? $limit : $hashtagLimit,
        );

        foreach ($hashtags as $hashtag) {
            $suggestions[] = $this->hashtagSuggestion($hashtag);
        }

        return response()->json([
            'suggestions' => array_values($suggestions),
        ]);
    }

    /**
     * @return array{type: string, url: string, handle: string, display_name: string, avatar_url: ?string, is_local: bool}
     */
    private function suggestionFromActor(Actor $actor): array
    {
        return [
            'type' => $actor->type,
            'url' => $actor->profileUrl(),
            'handle' => $actor->isLocal() ? $actor->preferred_username : $actor->handle(),
            'display_name' => $actor->displayNameForText(),
            'avatar_url' => $actor->avatarUrl(),
            'is_local' => $actor->isLocal(),
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
