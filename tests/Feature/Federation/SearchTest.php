<?php

namespace Tests\Feature\Federation;

use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Feature\LocalSearchTest;
use Tests\TestCase;

/**
 * Ricerca federata: un indirizzo "utente@dominio" locale reindirizza
 * direttamente al profilo, uno remoto passa da WebFinger + recupero del
 * documento Actor. Le ricerche a testo libero (non-handle) sono coperte da
 * {@see LocalSearchTest}.
 */
class SearchTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private const REMOTE_ACTOR_URI = 'https://social.example/users/nora';

    public function test_a_guest_cannot_access_the_search_page(): void
    {
        $this->get(route('search.create'))->assertRedirect(route('login'));
    }

    public function test_searching_a_local_handle_redirects_to_the_local_profile(): void
    {
        $viewer = $this->createFullAccount('cercatore');
        $target = $this->createFullAccount('trovato');
        $domain = config('openbook.domain');

        $response = $this->actingAs($viewer)->get(route('search.create', [
            'q' => "trovato@{$domain}",
        ]));

        $response->assertRedirect(route('profile.show', $target->username));
    }

    public function test_searching_an_unknown_local_handle_fails_validation(): void
    {
        $viewer = $this->createFullAccount('cercatore2');
        $domain = config('openbook.domain');

        $response = $this->actingAs($viewer)->from(route('search.create'))->get(route('search.create', [
            'q' => "fantasma@{$domain}",
        ]));

        $response->assertSessionHasErrors('q');
    }

    public function test_searching_a_remote_handle_resolves_it_via_webfinger_and_redirects_to_the_actor_page(): void
    {
        $viewer = $this->createFullAccount('cercatore3');

        Http::fake([
            'https://social.example/.well-known/webfinger*' => Http::response([
                'subject' => 'acct:nora@social.example',
                'links' => [
                    ['rel' => 'self', 'type' => 'application/activity+json', 'href' => self::REMOTE_ACTOR_URI],
                ],
            ], 200, ['Content-Type' => 'application/jrd+json']),
            self::REMOTE_ACTOR_URI => Http::response([
                'id' => self::REMOTE_ACTOR_URI,
                'type' => 'Person',
                'preferredUsername' => 'nora',
                'name' => 'Nora',
                'inbox' => self::REMOTE_ACTOR_URI.'/inbox',
                'publicKey' => [
                    'id' => self::REMOTE_ACTOR_URI.'#main-key',
                    'owner' => self::REMOTE_ACTOR_URI,
                    'publicKeyPem' => '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', [
            'q' => 'nora@social.example',
        ]));

        $actor = Actor::query()->where('uri', self::REMOTE_ACTOR_URI)->firstOrFail();
        $this->assertFalse($actor->is_local);
        $response->assertRedirect(route('actors.show', $actor));
    }

    public function test_searching_a_wordpress_style_handle_with_domain_as_username_works(): void
    {
        $viewer = $this->createFullAccount('cercatore_wp');
        $domain = 'thesnowmeltssomewhere.wordpress.com';
        $actorUri = 'https://'.$domain.'/?author=0';

        Http::fake([
            'https://'.$domain.'/.well-known/webfinger*' => Http::response([
                'subject' => 'acct:'.$domain.'@'.$domain,
                'links' => [
                    ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actorUri],
                ],
            ], 200, ['Content-Type' => 'application/jrd+json']),
            $actorUri => Http::response([
                'id' => $actorUri,
                'type' => 'Person',
                'preferredUsername' => $domain,
                'name' => 'The Snow Melts Somewhere',
                'inbox' => 'https://'.$domain.'/wp-json/activitypub/1.0/users/0/inbox',
                'publicKey' => [
                    'id' => $actorUri.'#main-key',
                    'owner' => $actorUri,
                    'publicKeyPem' => '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', [
            'q' => '@'.$domain.'@'.$domain,
        ]));

        $actor = Actor::query()->where('uri', $actorUri)->firstOrFail();
        $this->assertSame($domain, $actor->preferred_username);
        $response->assertRedirect(route('actors.show', $actor));
    }

    public function test_searching_an_address_at_an_unreachable_domain_fails_validation(): void
    {
        $viewer = $this->createFullAccount('cercatore4');

        Http::fake(['*' => Http::response('', 404)]);

        $response = $this->actingAs($viewer)->from(route('search.create'))->get(route('search.create', [
            'q' => 'chiunque@irraggiungibile.example',
        ]));

        $response->assertSessionHasErrors('q');
    }

    public function test_searching_a_mastodon_profile_url_resolves_the_actor_not_the_rss_feed(): void
    {
        $viewer = $this->createFullAccount('cercatore5');
        $profileUrl = 'https://social.example/@nora';
        $rssUrl = 'https://social.example/@nora.rss';

        Http::fake([
            $profileUrl => Http::response(
                '<!DOCTYPE html><html><head>'
                .'<link rel="alternate" type="application/rss+xml" href="'.htmlspecialchars($rssUrl).'">'
                .'<link rel="alternate" type="application/activity+json" href="'.self::REMOTE_ACTOR_URI.'">'
                .'</head><body>Nora</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://social.example/.well-known/webfinger*' => Http::response([
                'subject' => 'acct:nora@social.example',
                'links' => [
                    ['rel' => 'self', 'type' => 'application/activity+json', 'href' => self::REMOTE_ACTOR_URI],
                ],
            ], 200, ['Content-Type' => 'application/jrd+json']),
            self::REMOTE_ACTOR_URI => Http::response([
                'id' => self::REMOTE_ACTOR_URI,
                'type' => 'Person',
                'preferredUsername' => 'nora',
                'name' => 'Nora',
                'inbox' => self::REMOTE_ACTOR_URI.'/inbox',
                'publicKey' => [
                    'id' => self::REMOTE_ACTOR_URI.'#main-key',
                    'owner' => self::REMOTE_ACTOR_URI,
                    'publicKeyPem' => '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $rssUrl => Http::response(
                '<?xml version="1.0"?><rss version="2.0"><channel><title>Nora RSS</title></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', [
            'q' => $profileUrl,
        ]));

        $actor = Actor::query()->where('uri', self::REMOTE_ACTOR_URI)->firstOrFail();
        $this->assertTrue($actor->isPerson());
        $this->assertFalse($actor->isFeed());
        $this->assertSame(0, Actor::query()->where('type', Actor::TYPE_FEED)->count());
        $response->assertRedirect(route('actors.show', $actor));
    }

    public function test_searching_a_profile_url_uses_activitypub_html_alternate_when_webfinger_fails(): void
    {
        $viewer = $this->createFullAccount('cercatore6');
        $profileUrl = 'https://social.example/@nora';
        $rssUrl = 'https://social.example/@nora.rss';

        Http::fake([
            $profileUrl => Http::response(
                '<!DOCTYPE html><html><head>'
                .'<link rel="alternate" type="application/rss+xml" href="'.htmlspecialchars($rssUrl).'">'
                .'<link rel="alternate" type="application/activity+json" href="'.self::REMOTE_ACTOR_URI.'">'
                .'</head><body>Nora</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://social.example/.well-known/webfinger*' => Http::response('', 404),
            self::REMOTE_ACTOR_URI => Http::response([
                'id' => self::REMOTE_ACTOR_URI,
                'type' => 'Person',
                'preferredUsername' => 'nora',
                'name' => 'Nora',
                'inbox' => self::REMOTE_ACTOR_URI.'/inbox',
                'publicKey' => [
                    'id' => self::REMOTE_ACTOR_URI.'#main-key',
                    'owner' => self::REMOTE_ACTOR_URI,
                    'publicKeyPem' => '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $rssUrl => Http::response(
                '<?xml version="1.0"?><rss version="2.0"><channel><title>Nora RSS</title></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', [
            'q' => $profileUrl,
        ]));

        $actor = Actor::query()->where('uri', self::REMOTE_ACTOR_URI)->firstOrFail();
        $this->assertTrue($actor->isPerson());
        $this->assertSame(0, Actor::query()->where('type', Actor::TYPE_FEED)->count());
        $response->assertRedirect(route('actors.show', $actor));
    }
}
