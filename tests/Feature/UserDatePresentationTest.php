<?php

namespace Tests\Feature;

use App\Application\Services\MessageComposer;
use App\Application\Services\PostComposer;
use App\Domain\Events\Event;
use App\Domain\Moderation\AuditLog;
use App\Federation\Serialization\EventSerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Support\UserDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class UserDatePresentationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_poll_deadline_and_ajax_vote_are_local_without_changing_federation(): void
    {
        $this->travelTo(Carbon::parse('2027-01-15 12:00', config('app.timezone')));
        $author = $this->createFullAccount('datepoll');
        $viewer = $this->createFullAccount('datevoter');
        $viewer->settings->update(['locale' => 'en', 'timezone' => 'America/New_York']);
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Timezone poll', 'visibility' => 'public',
            'poll' => ['options' => ['Tea', 'Coffee'], 'multiple' => false, 'duration_seconds' => 86400],
        ]);
        $before = NoteSerializer::forPost($post);
        $deadline = $post->poll->ends_at->copy()->setTimezone('America/New_York')->locale('en')->translatedFormat('j M Y, H:i');
        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk()->assertSee($deadline)
            ->assertSee($post->poll->ends_at->toIso8601String());
        $vote = $this->postJson(route('posts.poll.vote', $post), [
            'choices' => [$post->poll->options->first()->id], 'options_version' => $post->poll->optionsVersion(),
        ])->assertOk();
        $votedAt = now()->setTimezone('America/New_York')->locale('en')->translatedFormat('j M Y, H:i');
        $this->assertStringContainsString('You voted on '.$votedAt, $vote->json('html'));
        $after = NoteSerializer::forPost($post->fresh());
        $this->assertSame($before['endTime'], $after['endTime']);
        $this->assertSame($before['published'], $after['published']);
    }

    public function test_messages_have_the_same_local_time_in_html_and_ajax(): void
    {
        Queue::fake();
        $sender = $this->createFullAccount('datesender');
        $viewer = $this->createFullAccount('datereceiver');
        $viewer->settings->update(['timezone' => 'America/New_York']);
        $message = app(MessageComposer::class)->send($sender->actor, $viewer->actor, 'Hello');
        $message->update(['published_at' => Carbon::parse('2027-01-16 01:30', config('app.timezone'))]);
        $expected = $message->published_at->copy()->setTimezone('America/New_York')->format('d/m/Y H:i');
        $this->actingAs($viewer)->get(route('messages.show', $message->conversation_id))
            ->assertOk()->assertSee($expected);
        $feed = $this->getJson(route('messages.feed', $message->conversation_id))->assertOk()
            ->assertJsonPath('messages.0.published_label', $expected)
            ->assertJsonPath('messages.0.published_at', $message->published_at->toIso8601String());
        $viewer->settings->update(['timezone' => 'Europe/Helsinki']);
        $this->actingAs($viewer->fresh())->withHeader('If-None-Match', $feed->headers->get('ETag'))
            ->getJson(route('messages.feed', $message->conversation_id))->assertOk()
            ->assertJsonPath('messages.0.published_label', $message->published_at->copy()->setTimezone('Europe/Helsinki')->format('d/m/Y H:i'));
    }

    public function test_event_keeps_its_own_time_and_shows_the_visitors_date_across_midnight(): void
    {
        $author = $this->createFullAccount('dateevent');
        $event = Event::create([
            'actor_id' => $author->actor->id, 'name' => 'Helsinki event', 'uri' => 'https://events.example/helsinki',
            'visibility' => 'public', 'status' => Event::STATUS_SCHEDULED,
            'start_at' => Carbon::parse('2027-01-16 00:30', 'Europe/Helsinki')->setTimezone(config('app.timezone')),
            'end_at' => Carbon::parse('2027-01-16 02:30', 'Europe/Helsinki')->setTimezone(config('app.timezone')),
            'timezone' => 'Europe/Helsinki',
        ]);
        $event->refresh();
        $before = EventSerializer::serialize($event);
        $this->withUnencryptedCookie(UserDate::COOKIE_NAME, 'America/New_York')
            ->withHeaders(['Accept-Language' => 'en'])->get(route('events.show', $event))
            ->assertOk()->assertSee('16 January 2027, 00:30')
            ->assertSee('15 January 2027, 17:30 in your time zone')
            ->assertSee('15 January 2027, 19:30 in your time zone');
        $card = view('events._card', ['event' => $event])->render();
        $this->assertStringContainsString('15 January 2027, 17:30 in your time zone', $card);
        $this->assertSame($before, EventSerializer::serialize($event->fresh()));
        $this->withUnencryptedCookie(UserDate::COOKIE_NAME, 'Europe/Helsinki')
            ->get(route('events.show', $event))->assertDontSee('in your time zone');
    }

    public function test_event_default_follows_the_preference_but_edit_and_old_input_keep_the_event_zone(): void
    {
        $author = $this->createFullAccount('eventzone');
        $author->settings->update(['timezone' => 'Europe/Helsinki']);
        $this->actingAs($author)->withUnencryptedCookie(UserDate::COOKIE_NAME, 'America/New_York')
            ->get(route('events.create'))->assertOk()->assertSee('value="Europe/Helsinki" selected', false);
        $event = Event::create([
            'actor_id' => $author->actor->id, 'name' => 'Rome event', 'uri' => 'https://events.example/rome', 'timezone' => 'Europe/Rome',
            'visibility' => 'public', 'status' => Event::STATUS_SCHEDULED, 'start_at' => now()->addDay(),
        ]);
        $this->get(route('events.edit', $event))->assertOk()->assertSee('value="Europe/Rome" selected', false);
        $this->withSession(['_old_input' => ['timezone' => 'Asia/Tokyo']])
            ->get(route('events.create'))->assertSee('value="Asia/Tokyo" selected', false);
    }

    public function test_profile_day_and_admin_audit_times_follow_the_viewer(): void
    {
        $admin = $this->createFullAccount('dateadmin', ['is_admin' => true]);
        $admin->settings->update(['timezone' => 'America/New_York', 'locale' => 'en']);
        $instant = Carbon::parse('2027-01-16 01:30', 'UTC')->setTimezone(config('app.timezone'));
        $admin->forceFill(['created_at' => $instant])->save();
        $this->actingAs($admin)->get(route('profile.show', $admin->username))->assertOk()
            ->assertSee('15 January 2027');
        AuditLog::create(['actor_id' => $admin->id, 'action' => 'timezone.test', 'created_at' => $instant]);
        $this->get(route('admin.audit.index'))->assertOk()
            ->assertSee('2027-01-15 20:30:00')->assertSee('Times in America/New_York');
        $this->assertTrue($instant->equalTo(AuditLog::firstOrFail()->created_at));
    }

    public function test_offset_only_remote_event_uses_its_offset_and_no_zone_uses_the_instance(): void
    {
        $event = new Event(['utc_offset_minutes' => 330]);
        $date = Carbon::parse('2027-01-15 22:30', 'UTC');
        $this->app->setLocale('en');
        $html = view('components.event-date', ['event' => $event, 'date' => $date, 'showTimezone' => true])->render();
        $this->assertStringContainsString('16 January 2027, 04:00', $html);
        $this->assertStringContainsString('UTC+05:30', $html);
        $event->utc_offset_minutes = null;
        $this->assertSame(config('app.timezone'), $event->timezoneForDisplay());
    }

    public function test_event_card_omits_matching_zones_and_repeats_the_date_only_when_the_day_changes(): void
    {
        request()->cookies->set(UserDate::COOKIE_NAME, 'Europe/Rome');
        $this->app->setLocale('en');
        $date = Carbon::parse('2026-10-22 13:00', 'UTC');
        $event = new Event(['timezone' => 'Europe/Helsinki']);
        $html = view('components.event-date', ['event' => $event, 'date' => $date, 'showTimezone' => true])->render();
        $this->assertStringContainsString('22 October 2026, 16:00', $html);
        $this->assertStringContainsString('(Europe/Helsinki)', $html);
        $this->assertStringContainsString('15:00 in your time zone', $html);
        $this->assertStringNotContainsString('22 October 2026, 15:00', $html);

        foreach ([new Event(['timezone' => 'Europe/Rome']), new Event(['utc_offset_minutes' => 120])] as $event) {
            $html = view('components.event-date', ['event' => $event, 'date' => $date, 'showTimezone' => true])->render();
            $this->assertStringContainsString('22 October 2026, 15:00', $html);
            $this->assertStringNotContainsString('ob-event-timezone', $html);
            $this->assertStringNotContainsString('in your time zone', $html);
        }
    }
}
