<?php

namespace App\Federation\Delivery;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcola le inbox remote di destinazione per un'attivita' in uscita e
 * accoda una {@see DeliverActivityJob} per ciascuna, deduplicate: piu'
 * follower sullo stesso server remoto condividono la stessa "sharedInbox",
 * quindi ricevono una sola richiesta HTTP invece di una per follower (come
 * raccomandato dalla specifica ActivityPub).
 *
 * Ogni consegna e' accodata con "afterCommit()": se il chiamante e' dentro
 * una transazione (come tutti i servizi applicativi di dominio), il job
 * parte solo dopo che la riga che lo ha originato e' visibile nel database,
 * evitando che un worker concorrente la trovi mancante.
 */
final class ActivityDelivery
{
    /**
     * Consegna un'attivita' a tutti i follower *remoti* accettati di un
     * Actor locale (usato per Create/Update/Delete di post pubblici).
     *
     * @param  array<string, mixed>  $activity
     */
    public function deliverToFollowers(Actor $localActor, array $activity): void
    {
        $this->dispatchToInboxes($this->remoteFollowerInboxes($localActor), $activity, $localActor);
    }

    /**
     * Consegna un'attivita' a un singolo Actor remoto (Follow, Accept,
     * Reject, Like, Undo mirati a un solo destinatario).
     *
     * @param  array<string, mixed>  $activity
     */
    public function deliverTo(Actor $signingActor, Actor $target, array $activity): void
    {
        if ($target->isLocal()) {
            return;
        }

        $inbox = $target->endpoints?->inbox;

        if (blank($inbox)) {
            return;
        }

        $this->dispatchToInboxes(collect([$inbox]), $activity, $signingActor);
    }

    /**
     * Consegna un "Announce" (o il suo "Undo") ai follower remoti di chi
     * condivide, e in piu', se distinto, direttamente all'autore originale
     * del post condiviso: cosi' viene notificato anche se non segue chi
     * condivide, prassi comune tra le implementazioni del Fediverso.
     *
     * @param  array<string, mixed>  $activity
     */
    public function deliverAnnounce(Actor $sharer, Actor $originalAuthor, array $activity): void
    {
        $inboxes = $this->remoteFollowerInboxes($sharer);

        if (! $originalAuthor->isLocal() && $originalAuthor->id !== $sharer->id) {
            $authorInbox = $originalAuthor->endpoints?->inbox;

            if (filled($authorInbox)) {
                $inboxes = $inboxes->push($authorInbox)->unique()->values();
            }
        }

        $this->dispatchToInboxes($inboxes, $activity, $sharer);
    }

    /**
     * Consegna un "Create"/"Delete" di un post o commento locale secondo la
     * sua audience: per visibilita' pubblica/non elencata/solo-follower va
     * ai follower remoti dell'autore, mentre per i messaggi diretti va
     * soltanto agli Actor remoti esplicitamente menzionati. In entrambi i
     * casi consegna anche direttamente a eventuali destinatari aggiuntivi
     * (es. l'autore del post padre di un commento), se remoti e distinti
     * dall'autore, cosi' da notificarli anche se non lo seguono.
     *
     * @param  array<string, mixed>  $activity
     * @param  list<?Actor>  $extraDirectTargets
     */
    public function deliverContent(Post|Comment $object, array $activity, array $extraDirectTargets = []): void
    {
        $author = $object->actor;

        if (! $author->isLocal()) {
            return;
        }

        $visibility = $object instanceof Post
            ? $object->visibility
            : ($object->post->visibility ?? Post::VISIBILITY_PUBLIC);

        if ($visibility === Post::VISIBILITY_DIRECT) {
            $mentionedActors = $object->mentions->map(fn ($mention) => $mention->actor)->filter();

            collect($extraDirectTargets)
                ->filter()
                ->concat($mentionedActors)
                ->unique('id')
                ->each(fn (Actor $target) => $this->deliverTo($author, $target, $activity));

            return;
        }

        $this->deliverToFollowers($author, $activity);

        collect($extraDirectTargets)
            ->filter(fn (?Actor $target) => $target !== null && ! $target->isLocal() && $target->id !== $author->id)
            ->unique('id')
            ->each(fn (Actor $target) => $this->deliverTo($author, $target, $activity));
    }

    /**
     * @return Collection<int, string>
     */
    private function remoteFollowerInboxes(Actor $localActor): Collection
    {
        $followerActorIds = DB::table('follows')
            ->where('following_id', $localActor->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->pluck('follower_id');

        if ($followerActorIds->isEmpty()) {
            return collect();
        }

        return Actor::query()
            ->whereIn('id', $followerActorIds)
            ->where('is_local', false)
            ->where('status', Actor::STATUS_ACTIVE)
            ->with('endpoints')
            ->get()
            ->map(fn (Actor $actor) => $actor->endpoints?->shared_inbox ?: $actor->endpoints?->inbox)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $inboxUrls
     * @param  array<string, mixed>  $activity
     */
    private function dispatchToInboxes(Collection $inboxUrls, array $activity, Actor $signingActor): void
    {
        if (! $signingActor->isLocal() || $inboxUrls->isEmpty()) {
            return;
        }

        foreach ($inboxUrls as $inboxUrl) {
            DeliverActivityJob::dispatch($inboxUrl, $activity, $signingActor->id)->afterCommit();
        }
    }
}
