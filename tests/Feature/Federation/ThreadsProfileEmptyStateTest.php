<?php

namespace Tests\Feature\Federation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ThreadsProfileEmptyStateTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_threads_profile_without_posts_explains_the_outbox_limit(): void
    {
        $viewer = $this->createFullAccount('threadsviewer');
        $remote = $this->createRemoteActor('barackobama', 'threads.net', [
            'uri' => 'https://threads.net/ap/users/17841400921600159/',
            'name' => 'Barack Obama',
        ]);
        $remote->endpoints->forceFill([
            'outbox' => 'https://threads.net/ap/users/17841400921600159/outbox/',
        ])->save();

        Http::fake([
            $remote->endpoints->outbox => Http::response([
                'id' => $remote->endpoints->outbox,
                'type' => 'OrderedCollection',
                'totalItems' => 1200,
            ], 200, ['Content-Type' => 'application/activity+json']),
            $remote->uri.'.atom' => Http::response('Not Found', 404),
        ]);

        $this->actingAs($viewer)
            ->get(route('actors.show', $remote))
            ->assertOk()
            ->assertSee(__('openbook.actors.threads_outbox_unavailable'))
            ->assertDontSee(__('openbook.profile.no_posts_yet'));
    }
}
