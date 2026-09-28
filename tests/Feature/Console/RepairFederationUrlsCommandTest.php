<?php

namespace Tests\Feature\Console;

use App\Application\Services\InstanceRelayActor;
use App\Federation\Actors\RelayActorUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class RepairFederationUrlsCommandTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_repair_changes_a_stale_person_uri_without_rewriting_the_relay_paths(): void
    {
        $person = $this->createFullAccount('andrea')->actor;
        $person->update(['uri' => 'https://localhost/users/andrea']);
        $relay = app(InstanceRelayActor::class)->getOrCreate();
        $relayUrls = RelayActorUrls::all();

        $this->artisan('openbook:repair-federation-urls', ['--dry-run' => true])
            ->expectsOutputToContain('andrea:')
            ->doesntExpectOutputToContain('relay:')
            ->assertExitCode(0);

        $this->assertSame('https://localhost/users/andrea', $person->fresh()->uri);

        $this->artisan('openbook:repair-federation-urls')->assertExitCode(0);

        $this->assertSame(url('/users/andrea'), $person->fresh()->uri);
        $this->assertSame($relayUrls['uri'], $relay->fresh()->uri);

        foreach (['inbox', 'outbox', 'followers', 'following', 'shared_inbox'] as $field) {
            $this->assertSame($relayUrls[$field], $relay->fresh()->endpoints->{$field});
        }
    }

    public function test_repair_uses_relay_paths_when_its_urls_are_stale(): void
    {
        $relay = app(InstanceRelayActor::class)->getOrCreate();
        $relay->update(['uri' => 'https://old.example/relay']);
        $relay->endpoints->update([
            'inbox' => 'https://old.example/inbox',
            'outbox' => 'https://old.example/relay/outbox',
            'followers' => 'https://old.example/relay/followers',
            'following' => 'https://old.example/relay/following',
            'shared_inbox' => 'https://old.example/inbox',
        ]);

        $this->artisan('openbook:repair-federation-urls', ['--dry-run' => true])
            ->expectsOutputToContain('relay:')
            ->expectsOutputToContain('https://old.example/relay → '.url('/relay'))
            ->doesntExpectOutputToContain(url('/users/relay'))
            ->assertExitCode(0);

        $this->artisan('openbook:repair-federation-urls')->assertExitCode(0);

        $urls = RelayActorUrls::all();
        $relay->refresh()->load('endpoints');
        $this->assertSame($urls['uri'], $relay->uri);

        foreach (['inbox', 'outbox', 'followers', 'following', 'shared_inbox'] as $field) {
            $this->assertSame($urls[$field], $relay->endpoints->{$field});
        }
    }
}
