<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\PersonQuery;
use App\Application\Services\FollowManager;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\PersonReferenceResource;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class FollowRequestController extends Controller
{
    use RespondsWithConditionalRest;

    public function index(Request $request, PersonQuery $persons): Response
    {
        $validator = Validator::make($request->query(), [
            'after' => ['sometimes', 'uuid'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(['personId'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'cursor' => ['prohibited'], 'page' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            $this->invalid($validator->errors()->toArray());
        }
        $values = $validator->validated();
        $limit = (int) ($values['limit'] ?? 20);
        $direction = $values['direction'] ?? 'asc';
        $pending = Follow::query()->where('following_id', $request->user()->actor->id)
            ->where('status', Follow::STATUS_PENDING)->select('follower_id');
        $items = $persons->available()->whereIn('actors.id', $pending)->with('user.profile')
            ->when(isset($values['after']), fn ($query) => $query->where('actors.id', $direction === 'asc' ? '>' : '<', $values['after']))
            ->orderBy('actors.id', $direction)->limit($limit + 1)->get();
        $page = $items->take($limit)->values();
        $response = response()->json(PersonReferenceResource::collection($page)->resolve($request));
        if ($items->count() > $limit) {
            $parameters = ['after' => $page->last()->id, 'limit' => $limit, 'sort' => 'personId', 'direction' => $direction];
            $response->headers->set('Link', '<'.$request->url().'?'.http_build_query($parameters).'>; rel="next"');
        }

        return $this->conditionalResponse($request, $response);
    }

    public function update(Request $request, string $personId, PersonQuery $persons, FollowManager $follows): Response
    {
        abort_unless($request->isJson(), 415);
        try {
            $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400);
        }
        if (! $body instanceof \stdClass || array_keys(get_object_vars($body)) !== ['followStatus'] || $body->followStatus !== 'accepted') {
            $this->invalid(['followStatus' => ['Supply only followStatus with the value accepted.']]);
        }
        DB::transaction(function () use ($request, $personId, $persons, $follows): void {
            $follow = $this->pending($request, $personId, $persons);
            $follows->accept($request->user()->actor, $follow->follower);
        });

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $personId, PersonQuery $persons, FollowManager $follows): Response
    {
        DB::transaction(function () use ($request, $personId, $persons, $follows): void {
            $follow = $this->pending($request, $personId, $persons);
            $follows->reject($request->user()->actor, $follow->follower);
        });

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function pending(Request $request, string $personId, PersonQuery $persons): Follow
    {
        abort_unless(Str::isUuid($personId), 404);
        Actor::query()->whereKey($request->user()->actor->id)->lockForUpdate()->firstOrFail();
        $follow = Follow::query()->where('following_id', $request->user()->actor->id)
            ->where('follower_id', $personId)->where('status', Follow::STATUS_PENDING)
            ->whereIn('follower_id', $persons->available()->select('actors.id'))
            ->with('follower')->lockForUpdate()->first();
        abort_if($follow === null, 404);

        return $follow;
    }

    private function invalid(array $errors): never
    {
        throw new HttpResponseException(response()->json([
            'type' => 'about:blank', 'title' => 'Unprocessable Content', 'status' => 422, 'errors' => (object) $errors,
        ], 422, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']));
    }
}
