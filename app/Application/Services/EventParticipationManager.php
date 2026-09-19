<?php

namespace App\Application\Services;

use App\Domain\Events\Event;
use App\Domain\Events\EventParticipation;
use App\Domain\Notifications\Notification;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EventParticipationManager
{
    public function __construct(
        private readonly ActivityDelivery $delivery,
        private readonly NotificationCreator $notifications,
    ) {}

    public function join(Actor $actor, Event $event): EventParticipation
    {
        if (! in_array($event->join_mode, ['free', 'restricted'], true)) {
            throw new InvalidArgumentException('Questo evento non accetta partecipazioni federate.');
        }

        $participation = DB::transaction(function () use ($actor, $event): EventParticipation {
            $participation = EventParticipation::query()->firstOrCreate(
                ['event_id' => $event->id, 'actor_id' => $actor->id],
                ['status' => EventParticipation::STATUS_PENDING],
            );

            if ($participation->status === EventParticipation::STATUS_REJECTED) {
                $participation->forceFill([
                    'status' => EventParticipation::STATUS_PENDING,
                    'responded_at' => null,
                    'activity_uri' => ActivitySerializer::eventJoinActivityUri($participation).'/tentativi/'.Str::uuid(),
                ])->save();
            }

            if (blank($participation->activity_uri)) {
                $participation->forceFill([
                    'activity_uri' => ActivitySerializer::eventJoinActivityUri($participation),
                ])->save();
            }

            return $participation;
        });

        $participation->loadMissing('actor', 'event.actor');
        $this->delivery->deliverTo($actor, $event->actor, ActivitySerializer::joinEvent($participation));

        return $participation;
    }

    public function leave(Actor $actor, Event $event): void
    {
        $participation = EventParticipation::query()
            ->where('event_id', $event->id)
            ->where('actor_id', $actor->id)
            ->first();

        if ($participation === null) {
            return;
        }

        $participation->loadMissing('actor', 'event.actor');
        $activity = $participation->status === EventParticipation::STATUS_ACCEPTED
            ? ActivitySerializer::leaveEvent($participation)
            : ActivitySerializer::undoJoinEvent($participation);

        $participation->delete();
        $this->delivery->deliverTo($actor, $event->actor, $activity);
    }

    public function respond(EventParticipation $participation, Actor $remoteActor, bool $accepted): void
    {
        $status = $accepted
            ? EventParticipation::STATUS_ACCEPTED
            : EventParticipation::STATUS_REJECTED;

        if ($participation->status === $status) {
            return;
        }

        $participation->forceFill(['status' => $status, 'responded_at' => now()])->save();
        $participation->loadMissing('actor');

        $this->notifications->notify(
            $participation->actor,
            $accepted ? Notification::TYPE_EVENT_JOIN_ACCEPTED : Notification::TYPE_EVENT_JOIN_REJECTED,
            $remoteActor,
            $participation,
        );
    }
}
