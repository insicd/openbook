<?php

namespace App\Http\Controllers\Federation;

use App\Application\Services\InstanceRelayActor;
use App\Federation\Serialization\ActorSerializer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class RelayActorController extends Controller
{
    public function show(InstanceRelayActor $relayActor): JsonResponse
    {
        return response()->json(
            ActorSerializer::serialize($relayActor->getOrCreate()),
            200,
            ['Content-Type' => 'application/activity+json; charset=utf-8'],
        );
    }

    public function outbox(): JsonResponse
    {
        $id = url('/relay/outbox');

        return response()->json([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id,
            'type' => 'OrderedCollection',
            'totalItems' => 0,
            'orderedItems' => [],
        ], 200, ['Content-Type' => 'application/activity+json; charset=utf-8']);
    }
}
