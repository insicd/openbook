<?php

namespace Tests\Feature\Federation;

use App\Domain\Notifications\Notification;
use App\Domain\SocialGraph\Follow;
use App\Federation\SocialGraph\OutgoingFollowConfirmer;
use App\Jobs\Federation\ConfirmOutgoingFollowJob;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class OutgoingFollowConfirmerTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_it_marks_a_pending_outgoing_follow_accepted_when_listed_in_remote_followers(): void
    {
        $local = $this->createFullAccount('localefollower');
        $remote = $this->createRemoteActor('bot', 'tags.example');

        $follow = Follow::query()->create([
            'follower_id' => $local->actor->id,
            'following_id' => $remote->id,
            'status' => Follow::STATUS_PENDING,
            'requested_at' => now()->subMinute(),
        ]);

        $followers = 'https://tags.example/users/bot/followers';
        $remote->endpoints->update(['followers' => $followers]);

        Http::fake([
            $followers => Http::response([
                'id' => $followers,
                'type' => 'OrderedCollection',
                'totalItems' => 1,
                'first' => $followers.'/1',
            ], 200, ['Content-Type' => 'application/activity+json']),
            $followers.'/1' => Http::response([
                'id' => $followers.'/1',
                'type' => 'OrderedCollectionPage',
                'orderedItems' => [
                    $local->actor->uri,
                    'https://altro.example/users/x',
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $confirmed = app(OutgoingFollowConfirmer::class)->confirm($follow->fresh(['follower', 'following.endpoints']));

        $this->assertTrue($confirmed);
        $this->assertDatabaseHas('follows', [
            'id' => $follow->id,
            'status' => Follow::STATUS_ACCEPTED,
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $local->id,
            'type' => Notification::TYPE_FOLLOW_ACCEPTED,
        ]);
    }

    public function test_it_leaves_the_follow_pending_when_not_listed_yet(): void
    {
        $local = $this->createFullAccount('ancorafuori');
        $remote = $this->createRemoteActor('chiuso', 'tags.example');

        $follow = Follow::query()->create([
            'follower_id' => $local->actor->id,
            'following_id' => $remote->id,
            'status' => Follow::STATUS_PENDING,
            'requested_at' => now()->subMinute(),
        ]);

        $followers = 'https://tags.example/users/chiuso/followers';
        $remote->endpoints->update(['followers' => $followers]);

        Http::fake([
            $followers => Http::response([
                'id' => $followers,
                'type' => 'OrderedCollection',
                'first' => $followers.'/1',
            ], 200, ['Content-Type' => 'application/activity+json']),
            $followers.'/1' => Http::response([
                'id' => $followers.'/1',
                'type' => 'OrderedCollectionPage',
                'orderedItems' => ['https://altro.example/users/x'],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $confirmed = app(OutgoingFollowConfirmer::class)->confirm($follow->fresh(['follower', 'following.endpoints']));

        $this->assertFalse($confirmed);
        $this->assertDatabaseHas('follows', [
            'id' => $follow->id,
            'status' => Follow::STATUS_PENDING,
        ]);
    }

    public function test_successful_follow_delivery_schedules_confirmation_job(): void
    {
        Queue::fake([ConfirmOutgoingFollowJob::class]);

        $sender = $this->createFullAccount('consegnasegui');
        $followId = '019fd27f-0bae-7200-bcbe-4739af98b247';
        $inboxUrl = 'https://tags.example/shared/inbox';

        Http::fake([$inboxUrl => Http::response('', 202)]);

        $job = new DeliverActivityJob($inboxUrl, [
            'type' => 'Follow',
            'id' => "https://openb.app/activities/follows/{$followId}",
        ], $sender->actor->id);

        app()->call([$job, 'handle']);

        Queue::assertPushed(ConfirmOutgoingFollowJob::class, fn (ConfirmOutgoingFollowJob $job): bool => $job->followId === $followId);
    }
}
