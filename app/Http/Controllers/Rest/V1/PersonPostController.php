<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Queries\PersonQuery;
use App\Domain\Feeds\FeedImporter;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Outbox\RemoteOutboxFetcher;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Concerns\ValidatesRestInput;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\PostResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class PersonPostController extends Controller
{
    use RespondsWithConditionalRest;
    use ValidatesRestInput;

    public function index(Request $request, string $personId, PersonQuery $persons, FeedQuery $feeds, RemoteOutboxFetcher $outbox, FeedImporter $importer): Response
    {
        abort_unless(Str::isUuid($personId), 404);
        $validator = Validator::make($request->query(), [
            'after' => [Rule::requiredIf($request->has('afterAt')), 'uuid'],
            'afterAt' => [Rule::requiredIf($request->has('after')), 'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'sort' => ['sometimes', Rule::in(['postTimelineAt'])],
            'direction' => ['sometimes', Rule::in(['desc'])],
            'limit' => ['prohibited'], 'cursor' => ['prohibited'],
            'page' => ['prohibited'], 'engine' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            $this->invalid($validator->errors()->toArray());
        }
        $values = $validator->validated();
        $owner = $persons->findActor($personId);
        abort_if($owner === null, 404);
        $this->authorizeWall($owner);
        try {
            if ($owner->isFeed()) {
                $source = $owner->feedSource;
                $interval = max(5, (int) config('openbook.feeds.min_fetch_interval_minutes', 30));
                if ($source !== null && ($source->last_fetched_at === null || $source->last_fetched_at->lte(now()->subMinutes($interval)))) {
                    $importer->import($owner);
                }
            } elseif (! $owner->isLocal()) {
                $outbox->fetchRecentContent($owner);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
        $owner = $persons->findActor($personId);
        abort_if($owner === null, 404);
        $this->authorizeWall($owner);
        $cursor = isset($values['after'])
            ? new FeedCursor(Carbon::parse($values['afterAt'])->setTimezone(config('app.timezone'))->toDateTimeString(), $values['after'])
            : null;
        $viewer = $request->user()->actor;
        $page = $feeds->forProfile($owner, $viewer, $cursor);
        Post::annotateViewerState($page->getCollection(), $viewer);
        $items = $page->getCollection()->map(fn (Post $post): array => (new PostResource($post))
            ->withTimelineContext(useShareSort: ! $owner->isGroup())->resolve($request));
        $response = response()->json($items->values()->all());
        if ($page->hasMorePages()) {
            $parameters = ['sort' => 'postTimelineAt', 'direction' => 'desc',
                'after' => $page->getCollection()->last()->id, 'afterAt' => $items->last()['postTimelineAt']];
            $response->headers->set('Link', '<'.$request->url().'?'.http_build_query($parameters).'>; rel="next"');
        }

        return $this->conditionalResponse($request, $response);
    }

    private function authorizeWall(Actor $actor): void
    {
        if ($actor->isGroup() && $actor->community !== null) {
            Gate::authorize('viewWall', $actor->community);
        }
    }
}
