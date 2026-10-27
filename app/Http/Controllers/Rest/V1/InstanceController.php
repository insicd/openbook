<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Services\InstanceSettings;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\InstanceResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InstanceController extends Controller
{
    use RespondsWithConditionalRest;

    public function show(Request $request, InstanceSettings $settings): Response
    {
        return $this->conditionalResponse($request, (new InstanceResource($settings))->toResponse($request));
    }
}
