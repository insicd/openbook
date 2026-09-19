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

        if ($event->actor?->isLocal()) {
            return $this->receiveJoin($actor, $event, null)
                ?? throw new InvalidArgumentException('Questo evento non accetta più partecipazioni.');
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

        if ($event->actor?->isLocal()) {
            $this->syncParticipantCount($event);
        }

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

    public function receiveJoin(Actor $actor, Event $event, ?string $activityUri): ?EventParticipation
    {
        if (! $event->actor?->isLocal()
            || ! $event->isOpenForInteractions()
            || ! in_array($event->join_mode, ['free', 'restricted'], true)) {
            return null;
        }

        $actionRequired = false;
        $participation = DB::transaction(function () use ($actor, $event, $activityUri, &$actionRequired): EventParticipation {
            $status = $event->join_mode === 'free'
                ? EventParticipation::STATUS_ACCEPTED
                : EventParticipation::STATUS_PENDING;
            $participation = EventParticipation::query()->firstOrNew([
                'event_id' => $event->id,
                'actor_id' => $actor->id,
            ]);
            $actionRequired = ! $participation->exists || $participation->status === EventParticipation::STATUS_REJECTED;

            if (! $actionRequired) {
                return $participation;
            }

            $participation->forceFill([
                'status' => $status,
                'activity_uri' => $activityUri,
                'responded_at' => $status === EventParticipation::STATUS_ACCEPTED ? now() : null,
            ])->save();

            if (blank($participation->activity_uri)) {
                $participation->forceFill([
                    'activity_uri' => ActivitySerializer::eventJoinActivityUri($participation),
                ])->save();
            }
            $this->syncParticipantCount($event);

            return $participation;
        });

        $participation->loadMissing('actor', 'event.actor');

        if (! $actionRequired) {
            return $participation;
        }

        $this->notifications->notify(
            $event->actor,
            $participation->status === EventParticipation::STATUS_ACCEPTED
                ? Notification::TYPE_EVENT_JOINED
                : Notification::TYPE_EVENT_JOIN_REQUEST,
            $actor,
            $participation,
        );

        if ($participation->status === EventParticipation::STATUS_ACCEPTED) {
            $this->delivery->deliverTo($event->actor, $actor, ActivitySerializer::acceptEventJoin($participation));
        }

        return $participation;
    }

    public function decideIncoming(EventParticipation $participation, Actor $organizer, bool $accepted): void
    {
        $participation->loadMissing('event.actor', 'actor');

        if ($participation->event->actor_id !== $organizer->id
            || ! $organizer->isLocal()
            || $participation->status !== EventParticipation::STATUS_PENDING) {
            throw new InvalidArgumentException('Puoi gestire solo le richieste per i tuoi eventi.');
        }

        $status = $accepted ? EventParticipation::STATUS_ACCEPTED : EventParticipation::STATUS_REJECTED;

        if ($participation->status === $status) {
            return;
        }

        DB::transaction(function () use ($participation, $status): void {
            $participation->forceFill(['status' => $status, 'responded_at' => now()])->save();
            $this->syncParticipantCount($participation->event);
        });

        $activity = $accepted
            ? ActivitySerializer::acceptEventJoin($participation)
            : ActivitySerializer::rejectEventJoin($participation);

        if ($participation->actor->isLocal()) {
            $this->notifications->notify(
                $participation->actor,
                $accepted ? Notification::TYPE_EVENT_JOIN_ACCEPTED : Notification::TYPE_EVENT_JOIN_REJECTED,
                $organizer,
                $participation,
            );
        } else {
            $this->delivery->deliverTo($organizer, $participation->actor, $activity);
        }
    }

    public function receiveLeave(Actor $actor, Event $event, ?string $activityUri = null): bool
    {
        $participation = EventParticipation::query()
            ->where('event_id', $event->id)
            ->where('actor_id', $actor->id)
            ->when($activityUri !== null, fn ($query) => $query->where('activity_uri', $activityUri))
            ->first();

        if ($participation === null || ! $event->actor?->isLocal()) {
            return false;
        }

        DB::transaction(function () use ($participation, $event): void {
            $participation->delete();
            $this->syncParticipantCount($event);
        });

        return true;
    }

    private function syncParticipantCount(Event $event): void
    {
        $event->forceFill([
            'participant_count' => $event->participations()
                ->where('status', EventParticipation::STATUS_ACCEPTED)
                ->count(),
        ])->save();
    }
}
