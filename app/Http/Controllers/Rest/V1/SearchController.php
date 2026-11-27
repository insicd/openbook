<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\PeopleSearchQuery;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Concerns\ValidatesRestInput;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\PersonReferenceResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class SearchController extends Controller
{
    use RespondsWithConditionalRest;
    use ValidatesRestInput;

    public function __invoke(Request $request, PeopleSearchQuery $peopleSearch): Response
    {
        $minLength = (int) config('openbook.search.min_length', 2);
        $validator = Validator::make($request->query(), [
            'q' => ['required', 'string', 'min:'.$minLength],
            'type' => ['sometimes', 'string', Rule::in(['profile'])],
        ]);
        if ($validator->fails()) {
            $this->invalid($validator->errors()->toArray());
        }

        $profiles = $peopleSearch->search(
            $validator->validated()['q'],
            (int) config('openbook.search.per_section', 10),
            $minLength,
        );
        $response = response()->json([
            'profiles' => PersonReferenceResource::collection($profiles)->resolve($request),
        ]);

        return $this->conditionalResponse($request, $response);
    }
}
