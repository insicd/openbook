<?php

namespace Tests\Feature\Posts;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Services\AnnounceManager;
use App\Application\Services\CommunityRegistrar;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Domain\SocialGraph\Follow;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $response = $this->actingAs($author)->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest']);

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

        $feed = $this->actingAs($quoter)->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $feed->assertOk();
        $feed->assertSee('La mia opinione sulla citazione.', false);

        $this->assertSame([$quote->id], app(FeedQuery::class)->forActor($quoter->actor)->getCollection()->pluck('id')->all());
        $this->assertSame([$quote->id], app(FeedQuery::class)->forProfile($quoter->actor, $quoter->actor)->getCollection()->pluck('id')->all());

        $follower = $this->createFullAccount('lettorecitazione');
        app(FollowManager::class)->follow($follower->actor, $quoter->actor);
        $this->assertSame([$quote->id], app(FeedQuery::class)->forActor($follower->actor)->getCollection()->pluck('id')->all());

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
        Queue::assertNotPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Announce');
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

    public function test_a_quote_does_not_reorder_or_hide_an_original_from_a_followed_author(): void
    {
        $author = $this->createFullAccount('autoreordine');
        $quoter = $this->createFullAccount('citatoreordine');
        $viewer = $this->createFullAccount('lettoreordine');
        app(FollowManager::class)->follow($viewer->actor, $author->actor);
        app(FollowManager::class)->follow($viewer->actor, $quoter->actor);
        $original = app(PostComposer::class)->compose($author->actor, ['body' => 'Originale.']);
        $original->update(['published_at' => now()->subDays(3)]);
        $quote = app(PostComposer::class)->compose($quoter->actor, [
            'body' => 'Citazione recente.',
            'quoted_post_id' => $original->id,
        ]);

        $first = app(FeedQuery::class)->forActor($viewer->actor, perPage: 1);
        $this->assertSame($quote->id, $first->getCollection()->sole()->id);
        $second = app(FeedQuery::class)->forActor($viewer->actor, FeedCursor::fromPost($first->getCollection()->sole(), useShareSort: true), perPage: 1);
        $this->assertSame($original->id, $second->getCollection()->sole()->id);
        $this->assertNull($second->getCollection()->sole()->sharedBy);
        $this->assertNull($second->getCollection()->sole()->shared_at);
        $this->assertFalse($second->hasMorePages());

        $ownOriginal = app(FeedQuery::class)->forActor($author->actor)->getCollection()->sole();
        $this->assertSame($original->id, $ownOriginal->id);
        $this->assertNull($ownOriginal->shared_at);

        // La citazione piu' recente non deve sopprimere un vero boost precedente.
        app(AnnounceManager::class)->announce($viewer->actor, $original);
        Announce::query()->where('actor_id', $viewer->actor->id)->update(['created_at' => now()->subDay()]);
        $items = app(FeedQuery::class)->forActor($viewer->actor)->getCollection();
        $this->assertSame([$quote->id, $original->id], $items->pluck('id')->all());
        $this->assertSame($viewer->actor->id, $items->last()->sharedBy->id);

        $profile = app(FeedQuery::class)->forProfile($author->actor, $viewer->actor)->getCollection()->sole();
        $this->assertSame($original->id, $profile->id);
        $this->assertNull($profile->shared_at);
    }

    public function test_quote_delivery_skips_the_boost_but_later_direct_shares_and_undo_are_delivered(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autoreconsegna');
        $quoter = $this->createFullAccount('citatoreconsegna');
        $remoteFollower = $this->createRemoteActor('lettorecitazioneremoto');
        Follow::query()->create([
            'follower_id' => $remoteFollower->id,
            'following_id' => $quoter->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);
        $original = app(PostComposer::class)->compose($author->actor, ['body' => 'Originale federato.']);
        $quote = app(PostComposer::class)->compose($quoter->actor, [
            'body' => 'Citazione federata.',
            'quoted_post_id' => $original->id,
        ]);

        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Create'
            && $job->activity['object']['id'] === url('/posts/'.$quote->id)
            && $job->activity['object']['quoteUrl'] === url('/posts/'.$original->id)
        );
        Queue::assertNotPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Announce');

        app(AnnounceManager::class)->announce($quoter->actor, $original);
        app(AnnounceManager::class)->announce($quoter->actor, $original);
        $boosts = Queue::pushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Announce');
        $this->assertCount(1, $boosts);
        $this->assertSame(url('/posts/'.$original->id), $boosts->first()->activity['object']);
        $this->assertSame(1, $original->fresh()->announces_count);
        $this->assertCount(2, app(FeedQuery::class)->forProfile($quoter->actor, $quoter->actor)->getCollection());

        Announce::query()->where('actor_id', $quoter->actor->id)->update(['created_at' => now()->subDay()]);
        config(['openbook.feed.per_page' => 1]);
        $first = app(FeedQuery::class)->forProfile($quoter->actor, $quoter->actor);
        $second = app(FeedQuery::class)->forProfile($quoter->actor, $quoter->actor, FeedCursor::fromPost($first->getCollection()->sole(), useShareSort: true));
        $this->assertSame($quote->id, $first->getCollection()->sole()->id);
        $this->assertSame($original->id, $second->getCollection()->sole()->id);
        $this->assertFalse($second->hasMorePages());

        app(AnnounceManager::class)->unannounce($quoter->actor, $original);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Undo' && $job->activity['object']['type'] === 'Announce');
        $this->assertSame([$quote->id], app(FeedQuery::class)->forActor($quoter->actor)->getCollection()->pluck('id')->all());
        $this->assertSame([$quote->id], app(FeedQuery::class)->forProfile($quoter->actor, $quoter->actor)->getCollection()->pluck('id')->all());
        $this->assertSame(1, $original->fresh()->announces_count);

        app(AnnounceManager::class)->announce($quoter->actor, $original);
        $boosts = Queue::pushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Announce');
        $this->assertCount(2, $boosts);
        $this->assertNotSame($boosts->first()->activity['id'], $boosts->last()->activity['id']);
        $this->assertSame(1, $original->fresh()->announces_count);
        $this->assertSame(1, Announce::query()->where('actor_id', $quoter->actor->id)->where('post_id', $original->id)->count());
    }

    public static function communityVisibility(): array
    {
        return ['public' => [false], 'private' => [true]];
    }

    #[DataProvider('communityVisibility')]
    public function test_community_announces_still_appear_in_the_feed_and_profile_and_are_delivered(bool $private): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('proprietariocommunityquote');
        $community = app(CommunityRegistrar::class)->register($owner, ['slug' => 'communityquote', 'name' => 'Community', 'is_private' => $private]);
        $remoteFollower = $this->createRemoteActor('lettorecommunityquote');
        Follow::query()->create([
            'follower_id' => $remoteFollower->id,
            'following_id' => $community->actor_id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);
        $post = app(PostComposer::class)->compose($owner->actor, ['body' => 'Post della community.', 'community_id' => $community->id]);

        $this->assertSame([$post->id], app(FeedQuery::class)->forActor($owner->actor)->getCollection()->pluck('id')->all());
        $this->assertSame([$post->id], app(FeedQuery::class)->forProfile($community->actor, $owner->actor)->getCollection()->pluck('id')->all());
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Announce' && $job->signingActorId === $community->actor_id
        );

        app(AnnounceManager::class)->announce($community->actor, $post, notify: false);
        $this->assertCount(1, Queue::pushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Announce'));
    }
}
