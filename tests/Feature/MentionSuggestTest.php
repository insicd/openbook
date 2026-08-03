<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class MentionSuggestTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_guests_cannot_request_mention_suggestions(): void
    {
        $this->getJson(route('mentions.suggest', ['q' => 'ali']))
            ->assertUnauthorized();
    }

    public function test_mention_suggestions_include_local_and_known_remote_people(): void
    {
        $viewer = $this->createFullAccount('menzionatore');
        $local = $this->createFullAccount('alice');
        $local->profile->update(['display_name' => 'Alice Locale']);
        $remote = $this->createRemoteActor('alicia', 'social.example', [
            'name' => 'Alicia Remota',
        ]);
        $this->createFullAccount('bruno');

        $response = $this->actingAs($viewer)
            ->getJson(route('mentions.suggest', ['q' => 'ali']));

        $response->assertOk();
        $response->assertJsonCount(2, 'suggestions');
        $response->assertJsonFragment([
            'insert' => '@alice ',
            'handle' => 'alice',
            'display_name' => 'Alice Locale',
            'is_local' => true,
        ]);
        $response->assertJsonFragment([
            'insert' => '@alicia@social.example ',
            'handle' => 'alicia@social.example',
            'display_name' => 'Alicia Remota',
            'is_local' => false,
        ]);
        $response->assertJsonMissing(['handle' => 'bruno']);
        $response->assertJsonMissing(['handle' => 'menzionatore']);
    }

    public function test_mention_suggestions_can_filter_by_domain_prefix(): void
    {
        $viewer = $this->createFullAccount('filtradominio');
        $this->createRemoteActor('carol', 'mastodon.example');
        $this->createRemoteActor('carol', 'pixelfed.example');

        $response = $this->actingAs($viewer)
            ->getJson(route('mentions.suggest', ['q' => 'carol@mas']));

        $response->assertOk();
        $response->assertJsonCount(1, 'suggestions');
        $response->assertJsonFragment([
            'handle' => 'carol@mastodon.example',
            'insert' => '@carol@mastodon.example ',
        ]);
    }

    public function test_composer_and_comment_fields_enable_mention_autocomplete(): void
    {
        $author = $this->createFullAccount('autocomposer');
        $viewer = $this->createFullAccount('autocviewer');

        $this->actingAs($viewer)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee('data-mention-autocomplete', false)
            ->assertSee('assets/js/mention-autocomplete.js', false)
            ->assertSee(route('mentions.suggest'), false);

        $post = app(\App\Application\Services\PostComposer::class)->compose($author->actor, [
            'body' => 'Post per commenti con autocomplete.',
            'visibility' => 'public',
        ]);

        $this->actingAs($viewer)
            ->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('id="comment-body"', false)
            ->assertSee('data-mention-autocomplete', false);
    }
}
