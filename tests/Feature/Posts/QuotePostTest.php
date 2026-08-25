<?php

namespace Tests\Feature\Posts;

use App\Application\Services\AnnounceManager;
use App\Application\Services\PostComposer;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class QuotePostTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_the_share_menu_offers_direct_share_and_quote(): void
    {
        $author = $this->createFullAccount('menushare');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post da condividere.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($author)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('class="ob-post__share-menu"', false);
        $response->assertSee(__('openbook.actions.announce_direct'), false);
        $response->assertSee(__('openbook.actions.announce_quote'), false);
        $response->assertSee(__('openbook.actions.announce_share_user'), false);
        $response->assertSee(route('posts.quote', $post), false);
        $response->assertSee(route('posts.share_to_user', $post), false);
    }

    public function test_quote_route_prepares_the_composer_with_the_original_post(): void
    {
        $author = $this->createFullAccount('autorecita');
        $quoter = $this->createFullAccount('citatore');
        $original = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Testo originale da citare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($quoter)->get(route('posts.quote', $original));

        $response->assertRedirect(route('feed.index', ['quote' => $original->id]));

        $home = $this->actingAs($quoter)->get('/home?quote='.$original->id);

        $home->assertOk();
        $home->assertSee('name="quoted_post_id"', false);
        $home->assertSee('value="'.$original->id.'"', false);
        $home->assertSee('Testo originale da citare.', false);
        $home->assertSee(__('openbook.composer.quoting', ['name' => $author->actor->displayName()]), false);
    }

    public function test_publishing_a_quote_creates_a_nested_card_in_the_feed(): void
    {
        $author = $this->createFullAccount('originale');
        $quoter = $this->createFullAccount('quotatore');
        $original = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Contenuto citato nestato.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($quoter)->post(route('posts.store'), [
            'body' => 'La mia opinione sulla citazione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'quoted_post_id' => $original->id,
        ]);

        $response->assertRedirect(route('posts.show', $quote = Post::query()->where('quoted_post_id', $original->id)->first()));

        $this->assertNotNull($quote);
        $this->assertSame('La mia opinione sulla citazione.', $quote->body);

        $detail = $this->actingAs($quoter)->get(route('posts.show', $quote));
        $detail->assertOk();
        $detail->assertSee('La mia opinione sulla citazione.', false);
        $detail->assertSee('Contenuto citato nestato.', false);
        $detail->assertSee('class="ob-post__quote"', false);
        $detail->assertSee('ob-post--embed', false);

        $feed = $this->actingAs($quoter)->get(route('feed.index'));
        $feed->assertOk();
        $feed->assertSee('La mia opinione sulla citazione.', false);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $author->id,
            'type' => Notification::TYPE_QUOTE,
            'actor_id' => $quoter->actor->id,
            'notifiable_id' => $quote->id,
        ]);

        $original->refresh();
        $this->assertSame(1, $original->announces_count);
        $this->assertDatabaseHas('announces', [
            'actor_id' => $quoter->actor->id,
            'post_id' => $original->id,
            'is_direct' => false,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $author->id,
            'type' => Notification::TYPE_SHARE,
            'actor_id' => $quoter->actor->id,
        ]);
    }

    public function test_quoting_does_not_double_count_if_already_shared_directly(): void
    {
        $author = $this->createFullAccount('giacondiviso');
        $quoter = $this->createFullAccount('ricondivisore');
        $original = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Gia condiviso.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        app(AnnounceManager::class)->announce($quoter->actor, $original);
        $original->refresh();
        $this->assertSame(1, $original->announces_count);

        app(PostComposer::class)->compose($quoter->actor, [
            'body' => 'Ora lo cito pure.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'quoted_post_id' => $original->id,
        ]);

        $original->refresh();
        $this->assertSame(1, $original->announces_count);
        $this->assertSame(1, Announce::query()->where('post_id', $original->id)->count());
    }

    public function test_after_a_quote_the_share_menu_still_offers_direct_share_not_unannounce(): void
    {
        $author = $this->createFullAccount('autorequoteui');
        $quoter = $this->createFullAccount('quotatoreui');
        $original = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post citato nel menu.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        app(PostComposer::class)->compose($quoter->actor, [
            'body' => 'La mia citazione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'quoted_post_id' => $original->id,
        ]);

        $response = $this->actingAs($quoter)->get(route('posts.show', $original));

        $response->assertOk();
        $response->assertSee('data-announced="0"', false);
    }

    public function test_direct_share_after_a_quote_shows_unannounce(): void
    {
        $author = $this->createFullAccount('autorequotedirect');
        $quoter = $this->createFullAccount('quotatoredirect');
        $original = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Prima cito poi riposto.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        app(PostComposer::class)->compose($quoter->actor, [
            'body' => 'Citazione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'quoted_post_id' => $original->id,
        ]);

        app(AnnounceManager::class)->announce($quoter->actor, $original);

        $response = $this->actingAs($quoter)->get(route('posts.show', $original));

        $response->assertOk();
        $response->assertSee('data-announced="1"', false);
    }

    public function test_unannouncing_a_direct_share_keeps_the_quote_announce_when_a_quote_exists(): void
    {
        $author = $this->createFullAccount('autoreunannouncequote');
        $quoter = $this->createFullAccount('quotatoreunannounce');
        $original = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Citato e ripostato.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        app(PostComposer::class)->compose($quoter->actor, [
            'body' => 'Citazione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'quoted_post_id' => $original->id,
        ]);

        app(AnnounceManager::class)->announce($quoter->actor, $original);
        app(AnnounceManager::class)->unannounce($quoter->actor, $original);

        $original->refresh();
        $announce = Announce::query()
            ->where('actor_id', $quoter->actor->id)
            ->where('post_id', $original->id)
            ->first();

        $this->assertNotNull($announce);
        $this->assertFalse($announce->is_direct);
        $this->assertSame(1, $original->announces_count);
    }

    public function test_a_remote_post_can_be_quoted(): void
    {
        Queue::fake();

        $quoter = $this->createFullAccount('quotaremoto');
        $remote = $this->createRemoteActor('remotecited');
        $original = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => 'https://remoto.example/users/remotecited/statuses/7',
            'body' => 'Nota remota citabile.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $quote = app(PostComposer::class)->compose($quoter->actor, [
            'body' => 'Cito dal fediverso.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'quoted_post_id' => $original->id,
        ]);

        $this->assertSame($original->id, $quote->quoted_post_id);
        $original->refresh();
        $this->assertSame(1, $original->announces_count);
    }

    public function test_an_invisible_post_cannot_be_quoted(): void
    {
        $author = $this->createFullAccount('privato');
        $stranger = $this->createFullAccount('estraneo');
        $original = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Solo per i miei follower.',
            'visibility' => Post::VISIBILITY_FOLLOWERS,
        ]);

        $this->actingAs($stranger)->get(route('posts.quote', $original))->assertNotFound();

        $this->actingAs($stranger)->post(route('posts.store'), [
            'body' => 'Tentativo di citazione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'quoted_post_id' => $original->id,
        ])->assertSessionHasErrors('quoted_post_id');
    }
}
