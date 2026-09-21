<?php

namespace Tests\Unit\Federation\Inbox;

use App\Domain\Events\Event;
use App\Federation\Inbox\RemoteEventObject;
use Tests\TestCase;

class RemoteEventObjectTest extends TestCase
{
    public function test_it_parses_a_balotta_style_event(): void
    {
        $event = [
            'id' => 'https://balotta.example/federation/m/14965',
            'type' => 'Event',
            'name' => 'Assemblea',
            'url' => 'https://balotta.example/event/assemblea',
            'startTime' => '2026-10-05T17:00:00+02:00',
            'endTime' => '2026-10-06T07:00:00+02:00',
            'attributedTo' => 'https://balotta.example/federation/u/agenda',
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'content' => '<p>Incontro di prova.</p>',
            'location' => [
                'id' => 'https://balotta.example/federation/p/665',
                'type' => 'Place',
                'name' => 'via San Carlo, 42',
                'address' => 'via San Carlo, 42, Bologna',
                'latitude' => 44.5004,
                'longitude' => 11.3406,
            ],
        ];

        $startAt = RemoteEventObject::startAt($event);

        $this->assertSame('https://balotta.example/federation/m/14965', RemoteEventObject::uri($event));
        $this->assertSame('Assemblea', RemoteEventObject::name($event));
        $this->assertSame('Incontro di prova.', RemoteEventObject::content($event));
        $this->assertSame(Event::VISIBILITY_PUBLIC, RemoteEventObject::visibility($event));
        $this->assertSame(120, RemoteEventObject::utcOffsetMinutes($event));
        $this->assertSame('2026-10-05 15:00:00', $startAt?->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06 05:00:00', RemoteEventObject::endAt($event, $startAt)->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('via San Carlo, 42, Bologna', RemoteEventObject::location($event)['address']);
    }

    public function test_it_parses_mobilizon_extensions_without_requiring_them(): void
    {
        $event = [
            'id' => 'https://mobilizon.example/events/abc',
            'type' => 'Event',
            'actor' => 'https://mobilizon.example/@creator',
            'attributedTo' => 'https://mobilizon.example/@group',
            'name' => 'Corso di teatro',
            'startTime' => '2026-09-29T18:30:00Z',
            'status' => 'TENTATIVE',
            'joinMode' => 'free',
            'category' => 'THEATRE',
            'inLanguage' => 'it',
            'participantCount' => 12,
            'isOnline' => true,
            'externalParticipationUrl' => 'https://meet.example/room',
            'cc' => ['https://mobilizon.example/@creator/followers'],
            'location' => [
                'type' => 'Place',
                'latitude' => 42.256499,
                'longitude' => 12.174499,
                'address' => [
                    'type' => 'PostalAddress',
                    'streetAddress' => 'Via della Mattonara',
                    'addressLocality' => 'Capranica',
                    'addressRegion' => 'Viterbo',
                    'postalCode' => '01012',
                    'addressCountry' => 'it',
                ],
            ],
            'attachment' => [[
                'type' => 'Link',
                'href' => 'https://example.test/tickets',
                'mediaType' => 'text/html',
                'name' => 'Biglietti',
            ]],
            'tag' => [[
                'type' => 'Hashtag',
                'name' => '#Teatro',
            ]],
        ];

        $this->assertSame('https://mobilizon.example/@creator', RemoteEventObject::creatorUri($event));
        $this->assertSame(Event::VISIBILITY_FOLLOWERS, RemoteEventObject::visibility($event));
        $this->assertSame(Event::STATUS_TENTATIVE, RemoteEventObject::status($event));
        $this->assertSame('free', RemoteEventObject::joinMode($event));
        $this->assertSame(12, RemoteEventObject::participantCount($event));
        $this->assertTrue(RemoteEventObject::isOnline($event));
        $this->assertSame('Capranica', RemoteEventObject::location($event)['locality']);
        $this->assertSame('IT', RemoteEventObject::location($event)['country_code']);
        $this->assertSame('https://example.test/tickets', RemoteEventObject::links($event)[0]['url']);
        $this->assertSame(['teatro'], RemoteEventObject::hashtags($event));
    }

    public function test_it_rejects_missing_core_fields_and_unsafe_urls(): void
    {
        $event = [
            'id' => 'http://unsafe.example/events/1',
            'type' => 'Event',
            'name' => '',
            'startTime' => 'not-a-date',
            'externalParticipationUrl' => 'javascript:alert(1)',
        ];

        $this->assertNull(RemoteEventObject::uri($event));
        $this->assertNull(RemoteEventObject::name($event));
        $this->assertNull(RemoteEventObject::startAt($event));
        $this->assertNull(RemoteEventObject::externalParticipationUrl($event));
    }

    public function test_it_normalizes_standard_and_namespaced_event_statuses(): void
    {
        $this->assertSame(Event::STATUS_CANCELLED, RemoteEventObject::status([
            'eventStatus' => 'https://schema.org/EventCancelled',
        ]));
        $this->assertSame(Event::STATUS_POSTPONED, RemoteEventObject::status([
            'ical:status' => 'POSTPONED',
        ]));
    }
}
