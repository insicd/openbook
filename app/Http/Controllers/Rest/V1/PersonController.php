<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\PersonFollowQuery;
use App\Application\Queries\PersonQuery;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\PersonReferenceResource;
use App\Http\Resources\Rest\V1\PersonResource;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class PersonController extends Controller
{
    use RespondsWithConditionalRest;

    public function show(Request $request, string $personId, PersonQuery $persons): Response
    {
        abort_unless(Str::isUuid($personId), 404);
        $person = $persons->find($personId);
        abort_if($person === null, 404);

        $response = (new PersonResource($person))->toResponse($request);

        return $this->conditionalResponse($request, $response);
    }

    public function followers(Request $request, string $personId, PersonQuery $persons, PersonFollowQuery $follows): Response
    {
        return $this->followList($request, $personId, $persons, $follows, true);
    }

    public function following(Request $request, string $personId, PersonQuery $persons, PersonFollowQuery $follows): Response
    {
        return $this->followList($request, $personId, $persons, $follows, false);
    }

    private function followList(Request $request, string $personId, PersonQuery $persons, PersonFollowQuery $follows, bool $followers): Response
    {
        abort_unless(Str::isUuid($personId), 404);
        $validator = Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'after' => ['sometimes', 'uuid'],
            'sort' => ['sometimes', Rule::in(['personId'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);
        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'errors' => $validator->errors()->toArray(),
            ], 422, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']));
        }
        $values = $validator->validated();
        $owner = $persons->findActor($personId);
        abort_if($owner === null, 404);
        if ($owner->isGroup() && $owner->community !== null) {
            Gate::authorize('viewMembers', $owner->community);
        }
        $limit = (int) ($values['limit'] ?? 20);
        $direction = $values['direction'] ?? 'asc';
        $page = $follows->page($owner, $followers, $limit, $values['after'] ?? null, $direction);
        $response = response()->json(PersonReferenceResource::collection($page['items'])->resolve($request));
        if ($page['hasMore']) {
            $query = http_build_query([
                'sort' => 'personId',
                'direction' => $direction,
                'limit' => $limit,
                'after' => $page['items']->last()->id,
            ]);
            $response->headers->set('Link', '<'.$request->url().'?'.$query.'>; rel="next"');
        }

        return $this->conditionalResponse($request, $response);
    }
}
