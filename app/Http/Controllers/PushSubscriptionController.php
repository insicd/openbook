<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\PushSubscription;
use App\Http\Requests\Settings\DeletePushSubscriptionRequest;
use App\Http\Requests\Settings\StorePushSubscriptionRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $endpointHash = hash('sha256', $data['endpoint']);
        $attributes = [
            'user_id' => $request->user()->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'expiration_time' => $data['expirationTime'] ?? null,
        ];

        // L'endpoint identifica la subscription anche quando lo stesso browser
        // viene esplicitamente riagganciato a un altro account locale.
        $subscription = PushSubscription::query()->firstOrNew(['endpoint_hash' => $endpointHash]);
        $subscription->fill($attributes);
        try {
            $subscription->save();
        } catch (UniqueConstraintViolationException) {
            // Due schede possono registrare contemporaneamente lo stesso
            // endpoint: la seconda aggiorna la riga creata dalla prima.
            $subscription = PushSubscription::query()->where('endpoint_hash', $endpointHash)->firstOrFail();
            $subscription->fill($attributes)->save();
        }

        return response()->json(['endpoint_hash' => $endpointHash]);
    }

    public function destroy(DeletePushSubscriptionRequest $request): JsonResponse
    {
        $endpointHash = hash('sha256', $request->validated('endpoint'));

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', $endpointHash)
            ->delete();

        return response()->json([], 204);
    }
}
