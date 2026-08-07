<?php

namespace Tests\Feature\Federation;

use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Riproduce il payload Mastodon reale per reply DM (poliversity.it → openb.app).
 */
class MastodonDmReplyPayloadTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private const PARENT_ID = '019fdcab-a5ba-72a4-b532-769de36989e9';

    private const REPLY_URI = 'https://poliversity.it/users/nuke/statuses/117054747363739171';

    private const PAYLOAD = <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams",{"ostatus":"http://ostatus.org#","atomUri":"ostatus:atomUri","inReplyToAtomUri":"ostatus:inReplyToAtomUri","conversation":"ostatus:conversation","sensitive":"as:sensitive","toot":"http://joinmastodon.org/ns#","votersCount":"toot:votersCount","quote":{"@id":"https://w3id.org/fep/044f#quote","@type":"@id"},"quoteUri":"http://fedibird.com/ns#quoteUri","_misskey_quote":"https://misskey-hub.net/ns#_misskey_quote","quoteAuthorization":{"@id":"https://w3id.org/fep/044f#quoteAuthorization","@type":"@id"},"gts":"https://gotosocial.org/ns#","interactionPolicy":{"@id":"gts:interactionPolicy","@type":"@id"},"canQuote":{"@id":"gts:canQuote","@type":"@id"},"automaticApproval":{"@id":"gts:automaticApproval","@type":"@id"},"manualApproval":{"@id":"gts:manualApproval","@type":"@id"},"litepub":"http://litepub.social/ns#","directMessage":"litepub:directMessage"}],"id":"https://poliversity.it/users/nuke/statuses/117054747363739171/activity","type":"Create","actor":"https://poliversity.it/users/nuke","published":"2026-08-07T14:41:38Z","to":["https://openb.app/users/nuke"],"cc":[],"object":{"id":"https://poliversity.it/users/nuke/statuses/117054747363739171","type":"Note","summary":null,"inReplyTo":"https://openb.app/posts/019fdcab-a5ba-72a4-b532-769de36989e9","published":"2026-08-07T14:41:38Z","url":"https://poliversity.it/@nuke/117054747363739171","attributedTo":"https://poliversity.it/users/nuke","to":["https://openb.app/users/nuke"],"cc":[],"sensitive":false,"atomUri":"https://poliversity.it/users/nuke/statuses/117054747363739171","inReplyToAtomUri":"https://openb.app/posts/019fdcab-a5ba-72a4-b532-769de36989e9","conversation":"https://poliversity.it/contexts/117001921753481031-117054745210749374","context":"https://poliversity.it/contexts/117001921753481031-117054745210749374","content":"<p><span class=\"h-card\" translate=\"no\"><a href=\"https://openb.app/@nuke\" class=\"u-url mention\" rel=\"nofollow noopener\" target=\"_blank\">@<span>nuke@openb.app</span></a></span> bo ancora non so</p>","contentMap":{"it":"<p><span class=\"h-card\" translate=\"no\"><a href=\"https://openb.app/@nuke\" class=\"u-url mention\" rel=\"nofollow noopener\" target=\"_blank\">@<span>nuke@openb.app</span></a></span> bo ancora non so</p>"},"directMessage":true,"interactionPolicy":{"canQuote":{"automaticApproval":["https://poliversity.it/users/nuke"]}},"attachment":[],"tag":[{"type":"Mention","href":"https://openb.app/users/nuke","name":"@nuke@openb.app"}],"replies":{"id":"https://poliversity.it/users/nuke/statuses/117054747363739171/replies","type":"Collection","first":{"type":"CollectionPage","next":"https://poliversity.it/users/nuke/statuses/117054747363739171/replies?only_other_accounts=true&page=true","partOf":"https://poliversity.it/users/nuke/statuses/117054747363739171/replies","items":[]}},"likes":{"id":"https://poliversity.it/users/nuke/statuses/117054747363739171/likes","type":"Collection","totalItems":0},"shares":{"id":"https://poliversity.it/users/nuke/statuses/117054747363739171/shares","type":"Collection","totalItems":0}}}
JSON;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openbook.domain' => 'openb.app']);
    }

    public function test_mastodon_payload_reply_lands_in_conversation_when_parent_is_openbook_dm(): void
    {
        $local = $this->createFullAccount('nuke');
        $local->actor->update(['uri' => 'https://openb.app/users/nuke']);
        $remote = $this->createRemoteActor('nuke', 'poliversity.it');

        $conversation = Conversation::query()->create([
            'participant_low_id' => Conversation::orderParticipantIds($local->actor->id, $remote->id)[0],
            'participant_high_id' => Conversation::orderParticipantIds($local->actor->id, $remote->id)[1],
            'last_message_at' => now()->subHour(),
        ]);

        Post::query()->create([
            'id' => self::PARENT_ID,
            'actor_id' => $local->actor->id,
            'body' => 'Ciao da OpenBook',
            'visibility' => Post::VISIBILITY_DIRECT,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
            'conversation_id' => $conversation->id,
        ]);

        $activity = json_decode(self::PAYLOAD, true, 512, JSON_THROW_ON_ERROR);

        $item = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => 'Create',
            'actor_uri' => $remote->uri,
            'payload' => self::PAYLOAD,
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        $status = app(InboxActivityProcessor::class)->process($item);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseMissing('comments', ['uri' => self::REPLY_URI]);

        $reply = Post::query()->where('uri', self::REPLY_URI)->first();
        $this->assertNotNull($reply, 'Reply should be stored as Post');
        $this->assertSame($conversation->id, $reply->conversation_id);
        $this->assertStringContainsString('bo ancora non so', $reply->body);

        $this->assertDatabaseMissing('notifications', [
            'type' => Notification::TYPE_COMMENT,
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $local->id,
            'type' => Notification::TYPE_DIRECT_MESSAGE,
        ]);
    }

    public function test_mastodon_payload_reply_with_public_parent_still_opens_conversation(): void
    {
        $local = $this->createFullAccount('nuke');
        $local->actor->update(['uri' => 'https://openb.app/users/nuke']);
        $remote = $this->createRemoteActor('nuke', 'poliversity.it');

        Post::query()->create([
            'id' => self::PARENT_ID,
            'actor_id' => $local->actor->id,
            'body' => 'Post padre senza conversation_id',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
            'conversation_id' => null,
        ]);

        $activity = json_decode(self::PAYLOAD, true, 512, JSON_THROW_ON_ERROR);
        $item = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => 'Create',
            'actor_uri' => $remote->uri,
            'payload' => self::PAYLOAD,
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        app(InboxActivityProcessor::class)->process($item);

        $this->assertDatabaseMissing('comments', ['uri' => self::REPLY_URI]);
        $reply = Post::query()->where('uri', self::REPLY_URI)->first();
        $this->assertNotNull($reply);
        $this->assertSame(Post::VISIBILITY_DIRECT, $reply->visibility);
        $this->assertNotNull($reply->conversation_id);
    }
}
