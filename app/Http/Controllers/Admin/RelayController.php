<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\InstanceRelayActor;
use App\Application\Services\RelayConfigurationManager;
use App\Application\Services\RelayHandshakeManager;
use App\Domain\Federation\Relay;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class RelayController extends Controller
{
    public function __construct(
        private readonly RelayConfigurationManager $manager,
        private readonly InstanceRelayActor $relayActor,
        private readonly RelayHandshakeManager $handshakes,
    ) {}

    public function index(): View
    {
        return view('admin.relays.index', [
            'relays' => Relay::query()->latest()->paginate(40),
            'relayActor' => $this->relayActor->getOrCreate(),
            'protocols' => [
                Relay::PROTOCOL_MASTODON => __('openbook.admin.relays.protocol_mastodon'),
                Relay::PROTOCOL_ACTOR => __('openbook.admin.relays.protocol_actor'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'protocol' => ['required', Rule::in([Relay::PROTOCOL_MASTODON, Relay::PROTOCOL_ACTOR])],
            'inbox_url' => ['required', 'string', 'max:2048'],
            'receive_enabled' => ['nullable', 'boolean'],
            'publish_enabled' => ['nullable', 'boolean'],
        ]);

        try {
            $this->manager->create($request->user(), [
                ...$data,
                'receive_enabled' => $request->boolean('receive_enabled'),
                'publish_enabled' => $request->boolean('publish_enabled'),
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inbox_url' => $exception->getMessage()]);
        }

        return back()->with('status', __('openbook.admin.relays.created'));
    }

    public function destroy(Request $request, Relay $relay): RedirectResponse
    {
        try {
            $this->manager->delete($request->user(), $relay);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inbox_url' => $exception->getMessage()]);
        }

        return back()->with('status', __('openbook.admin.relays.deleted'));
    }

    public function update(Request $request, Relay $relay): RedirectResponse
    {
        $request->validate([
            'receive_enabled' => ['nullable', 'boolean'],
            'publish_enabled' => ['nullable', 'boolean'],
        ]);

        $this->manager->updateDirections($request->user(), $relay, [
            'receive_enabled' => $request->boolean('receive_enabled'),
            'publish_enabled' => $request->boolean('publish_enabled'),
        ]);

        return back()->with('status', __('openbook.admin.relays.updated'));
    }

    public function subscribe(Request $request, Relay $relay): RedirectResponse
    {
        try {
            $this->handshakes->subscribe($request->user(), $relay);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['relay' => $exception->getMessage()]);
        }

        return back()->with('status', __('openbook.admin.relays.subscription_queued'));
    }

    public function unsubscribe(Request $request, Relay $relay): RedirectResponse
    {
        try {
            $this->handshakes->unsubscribe($request->user(), $relay);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['relay' => $exception->getMessage()]);
        }

        return back()->with('status', __('openbook.admin.relays.unsubscription_queued'));
    }
}
