<?php

namespace Tests\Feature\Federation;

use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Locations\GeoCity;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class EventInboxActivityTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_balotta_create_imports_a_complete_public_event_idempotently(): void
    {
        $agenda = $this->remoteActor('agenda', 'balotta.example', 'https://balotta.example/federation/u/agenda');
        $activity = $this->balottaCreate($agenda);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity, $agenda));
        $activity['id'] .= '-duplicate';
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity, $agenda));

        $this->assertDatabaseCount('events', 1);
        $event = Event::query()->with(['location', 'media', 'attributions'])->firstOrFail();
        $this->assertSame($agenda->id, $event->actor_id);
        $this->assertSame(Event::VISIBILITY_PUBLIC, $event->visibility);
        $this->assertSame('2026-10-05 15:00:00', $event->start_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(120, $event->utc_offset_minutes);
        $this->assertSame('via San Carlo, 42, Bologna', $event->location->address);
        $this->assertSame('https://balotta.example/media/cover.jpg', $event->media->first()->remote_url);
        $this->assertSame([$agenda->id], $event->attributions->pluck('id')->all());
    }

    public function test_remote_event_coordinates_fill_missing_city_and_country_from_local_catalog(): void
    {
        config()->set('openbook.locations.catalog_ready', true);

        GeoCity::query()->create([
            'geoname_id' => 3181928,
            'name' => 'Bologna',
            'ascii_name' => 'Bologna',
            'latitude' => 44.4938,
            'longitude' => 11.3387,
            'latitude_bucket' => 44,
            'longitude_bucket' => 11,
            'country_code' => 'IT',
            'country_name' => 'Italy',
            'admin1_code' => '05',
            'admin1_name' => 'Emilia-Romagna',
            'feature_code' => 'PPLA',
            'population' => 394843,
            'catalog_batch' => '00000000-0000-0000-0000-000000000001',
        ]);

        $agenda = $this->remoteActor('agenda-geo', 'balotta.example', 'https://balotta.example/federation/u/agenda-geo');

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($this->balottaCreate($agenda), $agenda));

        $location = Event::query()->with('location')->firstOrFail()->location;
        $this->assertSame(3181928, $location?->geo_city_id);
        $this->assertSame('Bologna', $location?->locality);
        $this->assertSame('Emilia-Romagna', $location?->region);
        $this->assertSame('IT', $location?->country_code);
        $this->assertSame('Italy', $location?->country_name);
        $this->assertSame('via San Carlo, 42, Bologna', $location?->address);
    }

    public function test_mobilizon_announce_imports_creator_organizer_links_and_then_merges_create(): void
    {
        $creator = $this->remoteActor('creator', 'mobilizon.example', 'https://mobilizon.example/@creator');
        $organizer = $this->remoteActor('organizer', 'mobilizon.example', 'https://mobilizon.example/@organizer');
        $eventDocument = $this->mobilizonEvent($creator, $organizer);
        $announce = [
            'id' => 'https://mobilizon.example/announces/1',
            'type' => 'Announce',
            'actor' => $organizer->uri,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => $eventDocument,
        ];

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($announce, $organizer));

        $event = Event::query()->with(['attributions', 'links', 'hashtags', 'location'])->firstOrFail();
        $this->assertSame($creator->id, $event->actor_id);
        $this->assertSame([$organizer->id], $event->attributions->pluck('id')->all());
        $this->assertSame('theatre', strtolower($event->category));
        $this->assertSame('free', $event->join_mode);
        $this->assertSame(0, $event->participant_count);
        $this->assertSame('Capranica', $event->location->locality);
        $this->assertSame('https://example.test/corsi', $event->links->first()->url);
        $this->assertSame(['teatro'], $event->hashtags->pluck('name')->all());
        $this->assertDatabaseHas('event_announces', [
            'event_id' => $event->id,
            'actor_id' => $organizer->id,
            'uri' => $announce['id'],
        ]);

        $eventDocument['name'] = 'Titolo aggiornato dalla Create';
        $create = [
            'id' => $eventDocument['id'].'/activity',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $eventDocument,
        ];

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($create, $creator));
        $this->assertDatabaseCount('events', 1);
        $this->assertSame('Titolo aggiornato dalla Create', $event->fresh()->name);
    }

    public function test_undo_event_announce_is_idempotent_and_keeps_the_event(): void
    {
        $creator = $this->remoteActor('creator', 'mobilizon.example', 'https://mobilizon.example/@creator');
        $organizer = $this->remoteActor('organizer', 'mobilizon.example', 'https://mobilizon.example/@organizer');
        $document = $this->mobilizonEvent($creator, $organizer);
        $announce = [
            'id' => 'https://mobilizon.example/announces/undo-me',
            'type' => 'Announce',
            'actor' => $organizer->uri,
            'object' => $document,
        ];
        $this->process($announce, $organizer);

        $undo = [
            'id' => 'https://mobilizon.example/activities/undo-1',
            'type' => 'Undo',
            'actor' => $organizer->uri,
            'object' => $announce,
        ];

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($undo, $organizer));
        $undo['id'] .= '-again';
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($undo, $organizer));
        $this->assertDatabaseCount('event_announces', 0);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_followers_event_records_only_authorized_local_recipients(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $follower = $this->createFullAccount('eventfollower')->actor;
        $stranger = $this->createFullAccount('eventstranger')->actor;
        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $creator->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);
        $document = $this->baseEvent($creator->uri);
        $document['cc'] = [$creator->uri.'/followers'];

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => 'https://events.example/activities/followers-event',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator, shared: true));

        $event = Event::query()->firstOrFail();
        $this->assertSame(Event::VISIBILITY_FOLLOWERS, $event->visibility);
        $this->assertDatabaseHas('event_recipients', ['event_id' => $event->id, 'actor_id' => $follower->id]);
        $this->assertDatabaseMissing('event_recipients', ['event_id' => $event->id, 'actor_id' => $stranger->id]);
    }

    public function test_create_signed_by_an_unrelated_actor_is_ignored(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $attacker = $this->remoteActor('attacker', 'evil.example', 'https://evil.example/users/attacker');
        $document = $this->baseEvent($creator->uri);

        $status = $this->process([
            'id' => 'https://evil.example/activities/fake-event',
            'type' => 'Create',
            'actor' => $attacker->uri,
            'object' => $document,
        ], $attacker);

        $this->assertSame(InboxItem::STATUS_IGNORED, $status);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_public_notes_replying_to_an_event_are_stored_as_a_read_only_thread(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $commenter = $this->remoteActor('commenter', 'social.example', 'https://social.example/users/commenter');
        $document = $this->baseEvent($creator->uri);
        $document['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
        $this->process([
            'id' => 'https://events.example/activities/event-with-comments',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator);

        $rootUri = 'https://social.example/notes/event-comment';
        $imageUrl = 'https://social.example/media/event-comment.jpg';
        $root = [
            'id' => $rootUri,
            'type' => 'Note',
            'attributedTo' => $commenter->uri,
            'inReplyTo' => $document['id'],
            'content' => '<p>Ci sarò :party:</p>',
            'published' => '2026-09-12T10:00:00Z',
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'tag' => [[
                'type' => 'Emoji',
                'name' => ':party:',
                'icon' => ['type' => 'Image', 'url' => 'https://social.example/emoji/party.png'],
            ]],
            'attachment' => [[
                'type' => 'Document',
                'mediaType' => 'image/jpeg',
                'url' => $imageUrl,
                'name' => 'Locandina commentata',
            ]],
        ];

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => $rootUri.'/activity',
            'type' => 'Create',
            'actor' => $commenter->uri,
            'object' => $root,
        ], $commenter));

        $root['content'] = '<p>Ci sarò sicuramente :party:</p>';
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => $rootUri.'/update',
            'type' => 'Update',
            'actor' => $commenter->uri,
            'object' => $root,
        ], $commenter));

        $replyUri = 'https://social.example/notes/event-reply';
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => $replyUri.'/activity',
            'type' => 'Create',
            'actor' => $commenter->uri,
            'object' => [
                'id' => $replyUri,
                'type' => 'Note',
                'attributedTo' => $commenter->uri,
                'inReplyTo' => $rootUri,
                'content' => '<p>Porto anche un amico.</p>',
                'published' => '2026-09-12T10:05:00Z',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $commenter));

        $this->assertDatabaseCount('event_comments', 2);
        $comment = EventComment::query()->where('uri', $rootUri)->with('media')->firstOrFail();
        $reply = EventComment::query()->where('uri', $replyUri)->firstOrFail();
        $this->assertSame('Ci sarò sicuramente :party:', $comment->body);
        $this->assertSame([':party:' => 'https://social.example/emoji/party.png'], $comment->custom_emojis);
        $this->assertSame($comment->id, $reply->parent_event_comment_id);
        $this->assertSame($imageUrl, $comment->media->first()->remote_url);

        $this->get(route('events.show', Event::query()->firstOrFail()))
            ->assertOk()
            ->assertSee('Ci sarò sicuramente')
            ->assertSee('Porto anche un amico.')
            ->assertSee('https://social.example/emoji/party.png', false)
            ->assertSee($imageUrl, false)
            ->assertDontSee('comment-body', false);
    }

    public function test_a_private_note_cannot_be_exposed_as_a_comment_on_a_public_event(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $commenter = $this->remoteActor('commenter', 'social.example', 'https://social.example/users/commenter');
        $document = $this->baseEvent($creator->uri);
        $document['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
        $this->process([
            'id' => 'https://events.example/activities/public-event',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator);

        $status = $this->process([
            'id' => 'https://social.example/notes/private/activity',
            'type' => 'Create',
            'actor' => $commenter->uri,
            'object' => [
                'id' => 'https://social.example/notes/private',
                'type' => 'Note',
                'attributedTo' => $commenter->uri,
                'inReplyTo' => $document['id'],
                'content' => '<p>Messaggio riservato.</p>',
                'to' => [$creator->uri],
            ],
        ], $commenter);

        $this->assertSame(InboxItem::STATUS_IGNORED, $status);
        $this->assertDatabaseCount('event_comments', 0);
    }

    public function test_event_comment_delete_is_an_authorized_tombstone(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $commenter = $this->remoteActor('commenter', 'social.example', 'https://social.example/users/commenter');
        $document = $this->baseEvent($creator->uri);
        $document['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
        $this->process([
            'id' => 'https://events.example/activities/comment-delete-event',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator);

        $commentUri = 'https://social.example/notes/delete-event-comment';
        $this->process([
            'id' => $commentUri.'/activity',
            'type' => 'Create',
            'actor' => $commenter->uri,
            'object' => [
                'id' => $commentUri,
                'type' => 'Note',
                'attributedTo' => $commenter->uri,
                'inReplyTo' => $document['id'],
                'content' => '<p>Da eliminare.</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $commenter);

        $attacker = $this->remoteActor('attacker', 'evil.example', 'https://evil.example/users/attacker');
        $delete = fn (Actor $signer, string $suffix) => $this->process([
            'id' => $commentUri.'/delete/'.$suffix,
            'type' => 'Delete',
            'actor' => $signer->uri,
            'object' => $commentUri,
        ], $signer);

        $this->assertSame(InboxItem::STATUS_IGNORED, $delete($attacker, 'fake'));
        $this->assertSame(InboxItem::STATUS_PROCESSED, $delete($commenter, 'valid'));
        $this->assertSame(EventComment::STATUS_DELETED, EventComment::query()->firstOrFail()->status);
        $this->assertSame('', EventComment::query()->firstOrFail()->body);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => $commentUri.'/update-after-delete',
            'type' => 'Update',
            'actor' => $commenter->uri,
            'object' => [
                'id' => $commentUri,
                'type' => 'Note',
                'attributedTo' => $commenter->uri,
                'inReplyTo' => $document['id'],
                'content' => '<p>Non deve riapparire.</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $commenter));

        $this->assertSame(EventComment::STATUS_DELETED, EventComment::query()->firstOrFail()->status);
        $this->assertSame('', EventComment::query()->firstOrFail()->body);
    }

    public function test_direct_event_delivered_to_multiple_personal_inboxes_keeps_every_grant(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $alice = $this->createFullAccount('eventalice')->actor;
        $bob = $this->createFullAccount('eventbob')->actor;
        $document = $this->baseEvent($creator->uri);

        foreach ([$alice, $bob] as $position => $target) {
            $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
                'id' => 'https://events.example/activities/direct-'.$position,
                'type' => 'Create',
                'actor' => $creator->uri,
                'object' => $document,
            ], $creator, target: $target));
        }

        $event = Event::query()->firstOrFail();
        $this->assertSame(Event::VISIBILITY_DIRECT, $event->visibility);
        $this->assertEqualsCanonicalizing(
            [$alice->id, $bob->id],
            $event->recipients()->pluck('actors.id')->all(),
        );
    }

    public function test_create_can_fetch_an_event_referenced_only_by_uri(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $local = $this->createFullAccount('eventfetcher')->actor;
        $document = $this->baseEvent($creator->uri);
        $document['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
        Http::fake([
            $document['id'] => Http::response($document, 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $status = $this->process([
            'id' => 'https://events.example/activities/create-by-reference',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document['id'],
        ], $creator, target: $local);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('events', [
            'uri' => $document['id'],
            'actor_id' => $creator->id,
        ]);
        Http::assertSentCount(1);
    }

    public function test_creator_can_update_an_event_without_losing_existing_recipient_grants(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $local = $this->createFullAccount('eventrecipient')->actor;
        $document = $this->baseEvent($creator->uri);

        $this->process([
            'id' => 'https://events.example/activities/create-updateable',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator, target: $local);

        $document['name'] = 'Titolo aggiornato';
        $document['content'] = '<p>Nuova descrizione.</p>';
        $document['status'] = 'CANCELLED';
        $document['location'] = ['type' => 'Place', 'name' => 'Nuova sede'];

        $status = $this->process([
            'id' => 'https://events.example/activities/update-event',
            'type' => 'Update',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator, target: $local);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $event = Event::query()->with('location')->firstOrFail();
        $this->assertSame('Titolo aggiornato', $event->name);
        $this->assertSame('Nuova descrizione.', $event->content);
        $this->assertSame(Event::STATUS_CANCELLED, $event->status);
        $this->assertSame('Nuova sede', $event->location->name);
        $this->assertDatabaseHas('event_recipients', ['event_id' => $event->id, 'actor_id' => $local->id]);
    }

    public function test_existing_organizer_can_update_but_an_unrelated_actor_cannot(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $organizer = $this->remoteActor('organizer', 'events.example', 'https://events.example/users/organizer');
        $attacker = $this->remoteActor('attacker', 'evil.example', 'https://evil.example/users/attacker');
        $document = $this->mobilizonEvent($creator, $organizer);

        $this->process([
            'id' => 'https://events.example/activities/create-managed',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator);

        $document['name'] = 'Aggiornato dall’organizzatore';
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => 'https://events.example/activities/update-by-organizer',
            'type' => 'Update',
            'actor' => $organizer->uri,
            'object' => $document,
        ], $organizer));

        $document['name'] = 'Tentativo malevolo';
        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process([
            'id' => 'https://evil.example/activities/update-event',
            'type' => 'Update',
            'actor' => $attacker->uri,
            'object' => $document,
        ], $attacker));
        $this->assertSame('Aggiornato dall’organizzatore', Event::query()->firstOrFail()->name);
    }

    public function test_an_older_create_cannot_overwrite_a_newer_event_update(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $document = $this->baseEvent($creator->uri);
        $document['to'] = ['https://www.w3.org/ns/activitystreams#Public'];

        $this->process([
            'id' => 'https://events.example/activities/create-before-update',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator);

        $updated = $document;
        $updated['name'] = 'Versione più recente';
        $updated['updated'] = '2026-09-12T12:00:00Z';
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => 'https://events.example/activities/newer-update',
            'type' => 'Update',
            'actor' => $creator->uri,
            'object' => $updated,
        ], $creator));

        $document['name'] = 'Create arrivata in ritardo';
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => 'https://events.example/activities/delayed-create',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator));

        $this->assertSame('Versione più recente', Event::query()->firstOrFail()->name);
    }

    public function test_delete_creates_an_idempotent_tombstone_and_prevents_resurrection(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $local = $this->createFullAccount('eventtombstone')->actor;
        $document = $this->baseEvent($creator->uri);
        $document['location'] = ['type' => 'Place', 'name' => 'Luogo da eliminare'];
        $document['attachment'] = [[
            'type' => 'Document',
            'mediaType' => 'image/jpeg',
            'url' => 'https://events.example/media/delete-me.jpg',
        ]];

        $this->process([
            'id' => 'https://events.example/activities/create-deleteable',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator, target: $local);
        $event = Event::query()->firstOrFail();
        $commenter = $this->remoteActor('commenter', 'social.example', 'https://social.example/users/event-commenter');
        $commentMediaUrl = 'https://social.example/media/comment-to-delete.jpg';
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => 'https://social.example/notes/comment-to-delete/activity',
            'type' => 'Create',
            'actor' => $commenter->uri,
            'object' => [
                'id' => 'https://social.example/notes/comment-to-delete',
                'type' => 'Note',
                'attributedTo' => $commenter->uri,
                'inReplyTo' => $document['id'],
                'content' => '<p>Commento da eliminare con l’evento.</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'attachment' => [[
                    'type' => 'Document',
                    'mediaType' => 'image/jpeg',
                    'url' => $commentMediaUrl,
                ]],
            ],
        ], $commenter, target: $local));
        $this->assertDatabaseHas('event_comments', ['event_id' => $event->id]);

        $delete = [
            'id' => 'https://events.example/activities/delete-event',
            'type' => 'Delete',
            'actor' => $creator->uri,
            'object' => ['id' => $document['id'], 'type' => 'Tombstone'],
        ];

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($delete, $creator));
        $delete['id'] .= '-again';
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($delete, $creator));

        $event->refresh();
        $this->assertTrue($event->isDeleted());
        $this->assertNotNull($event->deleted_at);
        $this->assertSame('', $event->name);
        $this->assertNull($event->content);
        $this->assertDatabaseMissing('event_locations', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('event_attachments', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('event_comments', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('media', ['remote_url' => $commentMediaUrl]);
        $this->assertDatabaseHas('event_recipients', ['event_id' => $event->id, 'actor_id' => $local->id]);

        $document['name'] = 'Non deve risorgere';
        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process([
            'id' => 'https://events.example/activities/recreate-deleted',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator));
        $this->assertTrue($event->fresh()->isDeleted());
    }

    public function test_unrelated_actor_cannot_delete_an_event(): void
    {
        $creator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');
        $attacker = $this->remoteActor('attacker', 'evil.example', 'https://evil.example/users/attacker');
        $document = $this->baseEvent($creator->uri);
        $document['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
        $this->process([
            'id' => 'https://events.example/activities/create-protected',
            'type' => 'Create',
            'actor' => $creator->uri,
            'object' => $document,
        ], $creator);

        $status = $this->process([
            'id' => 'https://evil.example/activities/delete-event',
            'type' => 'Delete',
            'actor' => $attacker->uri,
            'object' => $document['id'],
        ], $attacker);

        $this->assertSame(InboxItem::STATUS_IGNORED, $status);
        $this->assertFalse(Event::query()->firstOrFail()->isDeleted());
    }

    public function test_deleting_a_remote_actor_tombstones_authored_events_and_only_unlinks_distributed_ones(): void
    {
        $deletedActor = $this->remoteActor('departing', 'events.example', 'https://events.example/users/departing');
        $otherCreator = $this->remoteActor('creator', 'events.example', 'https://events.example/users/creator');

        $authored = $this->baseEvent($deletedActor->uri);
        $authored['id'] = 'https://events.example/events/authored';
        $authored['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
        $this->process([
            'id' => 'https://events.example/activities/create-authored',
            'type' => 'Create',
            'actor' => $deletedActor->uri,
            'object' => $authored,
        ], $deletedActor);

        $distributed = $this->mobilizonEvent($otherCreator, $deletedActor);
        $distributed['id'] = 'https://events.example/events/distributed';
        $this->process([
            'id' => 'https://events.example/activities/announce-distributed',
            'type' => 'Announce',
            'actor' => $deletedActor->uri,
            'object' => $distributed,
        ], $deletedActor);

        $authoredEvent = Event::query()->where('uri', $authored['id'])->firstOrFail();
        $distributedEvent = Event::query()->where('uri', $distributed['id'])->firstOrFail();
        $this->assertDatabaseHas('event_attributions', [
            'event_id' => $distributedEvent->id,
            'actor_id' => $deletedActor->id,
        ]);

        $status = $this->process([
            'id' => 'https://events.example/activities/delete-actor',
            'type' => 'Delete',
            'actor' => $deletedActor->uri,
            'object' => ['id' => $deletedActor->uri, 'type' => 'Tombstone'],
        ], $deletedActor);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertTrue($authoredEvent->fresh()->isDeleted());
        $this->assertFalse($distributedEvent->fresh()->isDeleted());
        $this->assertDatabaseMissing('event_attributions', [
            'event_id' => $distributedEvent->id,
            'actor_id' => $deletedActor->id,
        ]);
        $this->assertDatabaseMissing('event_announces', [
            'event_id' => $distributedEvent->id,
            'actor_id' => $deletedActor->id,
        ]);
    }

    /** @param array<string, mixed> $activity */
    private function process(array $activity, Actor $signer, ?Actor $target = null, bool $shared = false): string
    {
        $item = InboxItem::query()->create([
            'target_actor_id' => $target?->id,
            'is_shared' => $shared,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => $activity['type'],
            'actor_uri' => $signer->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        return app(InboxActivityProcessor::class)->process($item);
    }

    private function remoteActor(string $username, string $domain, string $uri): Actor
    {
        return $this->createRemoteActor($username, $domain, ['uri' => $uri]);
    }

    /** @return array<string, mixed> */
    private function balottaCreate(Actor $agenda): array
    {
        $event = $this->baseEvent($agenda->uri);
        unset($event['actor']);
        $event['id'] = 'https://balotta.example/federation/m/14965';
        $event['name'] = 'Coordinamento precari scuola';
        $event['startTime'] = '2026-10-05T17:00:00+02:00';
        $event['endTime'] = '2026-10-06T07:00:00+02:00';
        $event['content'] = '<p>Mutuo aiuto e assemblea.</p>';
        $event['location'] = [
            'id' => 'https://balotta.example/federation/p/665',
            'type' => 'Place',
            'name' => 'via San Carlo, 42',
            'address' => 'via San Carlo, 42, Bologna',
            'latitude' => 44.5004,
            'longitude' => 11.3406,
        ];
        $event['attachment'] = [[
            'type' => 'Document',
            'mediaType' => 'image/jpeg',
            'url' => 'https://balotta.example/media/cover.jpg',
            'name' => 'Locandina',
        ]];

        return [
            'id' => $event['id'].'#Create',
            'type' => 'Create',
            'actor' => $agenda->uri,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => $event,
        ];
    }

    /** @return array<string, mixed> */
    private function mobilizonEvent(Actor $creator, Actor $organizer): array
    {
        $event = $this->baseEvent($creator->uri);
        $event['id'] = 'https://mobilizon.example/events/abc';
        $event['actor'] = $creator->uri;
        $event['attributedTo'] = $organizer->uri;
        $event['name'] = 'Corso di teatro';
        $event['category'] = 'THEATRE';
        $event['inLanguage'] = 'it';
        $event['joinMode'] = 'free';
        $event['participantCount'] = 0;
        $event['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
        $event['location'] = [
            'type' => 'Place',
            'latitude' => 42.256499,
            'longitude' => 12.174499,
            'address' => [
                'type' => 'PostalAddress',
                'streetAddress' => 'Via della Mattonara',
                'addressLocality' => 'Capranica',
                'addressRegion' => 'Viterbo',
                'postalCode' => '01012',
                'addressCountry' => 'IT',
            ],
        ];
        $event['attachment'] = [
            [
                'type' => 'Document',
                'mediaType' => 'image/jpeg',
                'url' => 'https://mobilizon.example/media/banner.jpg',
                'name' => 'Banner',
            ],
            [
                'type' => 'Link',
                'href' => 'https://example.test/corsi',
                'mediaType' => 'text/html',
                'name' => 'Website',
            ],
        ];
        $event['tag'] = [['type' => 'Hashtag', 'name' => '#teatro']];

        return $event;
    }

    /** @return array<string, mixed> */
    private function baseEvent(string $creatorUri): array
    {
        return [
            'id' => 'https://events.example/events/base',
            'type' => 'Event',
            'actor' => $creatorUri,
            'attributedTo' => $creatorUri,
            'name' => 'Evento di prova',
            'content' => '<p>Descrizione.</p>',
            'startTime' => '2026-10-10T18:00:00Z',
            'endTime' => '2026-10-10T21:00:00Z',
            'published' => '2026-09-10T12:00:00Z',
            'updated' => '2026-09-10T12:00:00Z',
            'to' => [],
            'cc' => [],
        ];
    }
}
