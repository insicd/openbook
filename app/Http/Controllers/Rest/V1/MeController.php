<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\PersonQuery;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\MeResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MeController extends Controller
{
    use RespondsWithConditionalRest;

    public function show(Request $request, PersonQuery $persons): Response
    {
        $person = $persons->find($request->user()->actor->id);
        abort_if($person === null, 404);

        return $this->conditionalResponse($request, (new MeResource($person))->toResponse($request));
    }
}
