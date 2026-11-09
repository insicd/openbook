<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Services\PostPublicationCanceller;
use App\Domain\Posts\PendingPostPublication;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\PostPublicationResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class PostPublicationController extends Controller
{
    public function show(Request $request, string $postPublicationId): Response
    {
        abort_unless(Str::isUuid($postPublicationId), 404);
        $publication = PendingPostPublication::query()->where('actor_id', $request->user()->actor->id)
            ->with('post:id,status')->find($postPublicationId);
        abort_if($publication === null, 404);

        return (new PostPublicationResource($publication))->toResponse($request)
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $postPublicationId, PostPublicationCanceller $canceller): Response
    {
        abort_unless(Str::isUuid($postPublicationId), 404);
        $result = $canceller->cancel($request->user()->actor, $postPublicationId);
        abort_if($result === 'missing', 404);
        abort_if($result === 'processing', 409);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
