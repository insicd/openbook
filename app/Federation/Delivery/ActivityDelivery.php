<?php

namespace App\Federation\Delivery;

use App\Application\Services\DomainBlockManager;
use App\Application\Services\InstanceRelayActor;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Federation\Relay;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Serialization\RelayActivitySerializer;
use App\Infrastructure\Security\LinkedData\LinkedDataSignature;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function __construct(
        private readonly LinkedDataSignature $linkedDataSignatures,
        private readonly InstanceRelayActor $instanceRelayActor,
    ) {}

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

        $inbox = $this->remoteActorInbox($target);

        if (blank($inbox)) {
            Log::channel('single')->warning('federation.delivery_skipped', [
                'reason' => 'missing_inbox',
                'target_uri' => $target->uri,
                'activity_type' => $activity['type'] ?? null,
                'activity_id' => $activity['id'] ?? null,
            ]);

            return;
        }

        $this->dispatchToInboxes(collect([$inbox]), $activity, $signingActor);
    }

    /**
     * Consegna a un endpoint amministrativamente configurato che non e'
     * necessariamente rappresentato da un Actor remoto in cache (relay).
     *
     * @param  array<string, mixed>  $activity
     */
    public function deliverToInbox(Actor $signingActor, string $inboxUrl, array $activity, ?string $relayId = null): void
    {
        $this->dispatchToInboxes(collect([$inboxUrl]), $activity, $signingActor, $relayId);
    }

    /**
     * Consegna un "Announce" (o il suo "Undo") ai follower remoti di chi
     * condivide, e in piu', se distinto, direttamente all'autore originale
     * del post condiviso: cosi' viene notificato anche se non segue chi
     * condivide, prassi comune tra le implementazioni del Fediverso.
     *
     * @param  array<string, mixed>  $activity
     */
    public function deliverAnnounce(Actor $sharer, Post $post, array $activity): void
    {
        $inboxes = $this->remoteFollowerInboxes($sharer);
        $originalAuthor = $post->actor;

        if (! $originalAuthor->isLocal() && ! $originalAuthor->isFeed() && $originalAuthor->id !== $sharer->id) {
            $authorInbox = $originalAuthor->endpoints?->shared_inbox
                ?: $originalAuthor->endpoints?->inbox;

            if (filled($authorInbox)) {
                $inboxes = $inboxes->push($authorInbox)->unique()->values();
            }
        }

        $this->dispatchToInboxes($inboxes, $activity, $sharer);

        if ($this->isPublicContent($post) && ! $post->isInPrivateCommunity()) {
            $this->dispatchToMastodonRelays($sharer, $activity, $inboxes);
        }
    }

    /**
     * Consegna un "Create"/"Update"/"Delete" di un post o commento locale
     * secondo la sua audience: per visibilita' pubblica/non elencata/solo-
     * follower va ai follower remoti dell'autore, mentre per i messaggi
     * diretti va soltanto agli Actor remoti esplicitamente menzionati. I
     * post in community privata vanno ai follower remoti del Group (firmati
     * dall'autore), non ai follower dell'autore. In tutti i casi consegna
     * anche direttamente a eventuali destinatari aggiuntivi (es. l'autore
     * del post padre di un commento), se remoti e distinti dall'autore.
     *
     * @param  array<string, mixed>  $activity
     * @param  list<?Actor>  $extraDirectTargets
     */
    public function deliverContent(
        Post|Comment|Event|EventComment $object,
        array $activity,
        array $extraDirectTargets = [],
        ?array $relayActivity = null,
    ): void {
        $author = $object->actor;

        if (! $author->isLocal()) {
            return;
        }

        if ($object instanceof Event) {
            if (! in_array($object->visibility, [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_UNLISTED], true)) {
                return;
            }

            $object->loadMissing('mentions.actor');
            $directTargets = collect($extraDirectTargets)
                ->concat($object->mentions->pluck('actor'))
                ->filter(fn (?Actor $target) => $target !== null && ! $target->isLocal() && $target->id !== $author->id)
                ->unique('id');
            $normalInboxes = $this->remoteContentInboxes($author, $directTargets);

            $this->dispatchToInboxes($normalInboxes, $activity, $author);
            $this->dispatchContentToRelays(
                $object,
                $relayActivity ?? $activity,
                $normalInboxes,
                force: $relayActivity !== null,
            );

            return;
        }

        if ($object instanceof EventComment) {
            $object->loadMissing('event', 'mentions.actor');

            if (! in_array($object->event->visibility, [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_UNLISTED], true)) {
                return;
            }

            $directTargets = collect($extraDirectTargets)
                ->concat($object->mentions->pluck('actor'))
                ->filter(fn (?Actor $target) => $target !== null && ! $target->isLocal() && $target->id !== $author->id)
                ->unique('id');
            $normalInboxes = $this->remoteContentInboxes($author, $directTargets);

            $this->dispatchToInboxes($normalInboxes, $activity, $author);

            if ($object->event->visibility === Event::VISIBILITY_PUBLIC) {
                $this->dispatchContentToRelays($object, $activity, $normalInboxes);
            }

            return;
        }

        if ($object instanceof Post && $object->isInPrivateCommunity()) {
            $object->loadMissing('community.actor', 'mentions.actor');
            $group = $object->community?->actor;

            if ($group !== null) {
                $this->dispatchToInboxes($this->remoteFollowerInboxes($group), $activity, $author);
            }

            collect($extraDirectTargets)
                ->filter(fn (?Actor $target) => $target !== null && ! $target->isLocal() && $target->id !== $author->id)
                ->unique('id')
                ->each(fn (Actor $target) => $this->deliverTo($author, $target, $activity));

            return;
        }

        if ($object instanceof Comment) {
            $object->loadMissing('post.community.actor', 'mentions.actor');

            if ($object->post->isInPrivateCommunity()) {
                // Come i Create dei post: niente fan-out ai follower
                // dell'autore. Solo membri remoti del Group (+ destinatari
                // diretti, es. autore del post padre se remoto).
                $group = $object->post->community->actor;
                $this->dispatchToInboxes($this->remoteFollowerInboxes($group), $activity, $author);

                collect($extraDirectTargets)
                    ->filter(fn (?Actor $target) => $target !== null && ! $target->isLocal() && $target->id !== $author->id)
                    ->unique('id')
                    ->each(fn (Actor $target) => $this->deliverTo($author, $target, $activity));

                return;
            }
        }

        $visibility = $object instanceof Post
            ? $object->visibility
            : ($object->post->visibility ?? Post::VISIBILITY_PUBLIC);

        if ($visibility === Post::VISIBILITY_DIRECT) {
            $mentionedActors = $object->mentions->map(fn ($mention) => $mention->actor)->filter();
            $normalInboxes = $this->remoteActorInboxes(collect($extraDirectTargets)
                ->filter()
                ->concat($mentionedActors)
                ->unique('id'));

            $this->dispatchToInboxes($normalInboxes, $activity, $author);

            if ($relayActivity !== null) {
                $this->dispatchContentToRelays($object, $relayActivity, $normalInboxes, force: true);
            }

            return;
        }

        // FEP-1b12: il Create deve raggiungere l'inbox del Group remoto
        // (locale il ritrasmette il Group stesso con Announce).
        $remoteGroupMentions = $object->mentions
            ->map(fn ($mention) => $mention->actor)
            ->filter(fn (?Actor $target) => $target !== null && $target->isGroup() && ! $target->isLocal());

        $directTargets = collect($extraDirectTargets)
            ->concat($remoteGroupMentions)
            ->filter(fn (?Actor $target) => $target !== null && ! $target->isLocal() && $target->id !== $author->id)
            ->unique('id');
        $normalInboxes = $this->remoteContentInboxes($author, $directTargets);

        $this->dispatchToInboxes($normalInboxes, $activity, $author);

        if (($object instanceof Post || $object instanceof Comment)
            && ($relayActivity !== null || $visibility === Post::VISIBILITY_PUBLIC)) {
            $this->dispatchContentToRelays($object, $relayActivity ?? $activity, $normalInboxes, force: $relayActivity !== null);
        }
    }

    /**
     * @param  Collection<int, Actor>  $actors
     * @return Collection<int, string>
     */
    private function remoteActorInboxes(Collection $actors): Collection
    {
        return $actors
            ->map(fn (Actor $actor) => $this->remoteActorInbox($actor))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, Actor>  $directTargets
     * @return Collection<int, string>
     */
    private function remoteContentInboxes(Actor $author, Collection $directTargets): Collection
    {
        return $this->remoteFollowerInboxes($author)
            ->concat($this->remoteActorInboxes($directTargets))
            ->filter()
            ->unique()
            ->values();
    }

    private function remoteActorInbox(Actor $target): ?string
    {
        if ($target->isLocal()) {
            return null;
        }

        $target->loadMissing('endpoints');
        $inbox = $target->endpoints?->shared_inbox ?: $target->endpoints?->inbox;

        if (blank($inbox)) {
            $refreshed = app(RemoteActorResolver::class)->refresh($target);

            if ($refreshed !== null) {
                $inbox = $refreshed->endpoints?->shared_inbox ?: $refreshed->endpoints?->inbox;
            }
        }

        return filled($inbox) ? (string) $inbox : null;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @param  Collection<int, string>  $alreadyAddressed
     */
    private function dispatchContentToRelays(
        Post|Comment|Event|EventComment $object,
        array $activity,
        Collection $alreadyAddressed,
        bool $force = false,
    ): void {
        if (! $object->actor->isLocal()
            || ! in_array($activity['type'] ?? null, ['Create', 'Update', 'Delete'], true)
            || (! $force && ! $this->isPublicContent($object))) {
            return;
        }

        $mastodonRelayInboxes = $this->dispatchToMastodonRelays($object->actor, $activity, $alreadyAddressed);

        $this->dispatchContentToActorRelayFollowers(
            $object,
            $activity,
            $alreadyAddressed->concat($mastodonRelayInboxes)->unique()->values(),
        );
    }

    /**
     * I relay Mastodon-like ricevono direttamente l'attivita' firmata
     * dall'Actor locale. La selezione e la deduplicazione sono condivise
     * tra contenuti e boost, inclusi i relativi Undo.
     *
     * @param  array<string, mixed>  $activity
     * @param  Collection<int, string>  $alreadyAddressed
     * @return Collection<int, string>
     */
    private function dispatchToMastodonRelays(
        Actor $signingActor,
        array $activity,
        Collection $alreadyAddressed,
    ): Collection {
        $relays = Relay::query()
            // Gli Actor relay pubblicano Announce tecnici: verranno aggiunti
            // dal flusso dedicato, non devono ricevere il payload Mastodon.
            ->where('protocol', Relay::PROTOCOL_MASTODON)
            ->where('state', Relay::STATE_ACCEPTED)
            ->where('publish_enabled', true)
            ->get(['id', 'inbox_url']);

        $relays->each(function (Relay $relay) use ($activity, $alreadyAddressed, $signingActor): void {
            if ($alreadyAddressed->contains($relay->inbox_url)) {
                return;
            }

            $this->dispatchToInboxes(collect([$relay->inbox_url]), $activity, $signingActor, $relay->id);
        });

        return $relays->pluck('inbox_url')->filter()->unique()->values();
    }

    /**
     * Gli Actor relay seguono l'Actor tecnico dell'istanza e ricevono un
     * Announce per Create/Update. I Delete devono invece restare firmati
     * dall'autore dell'oggetto, affinche' il destinatario possa verificarne
     * l'ownership anche quando l'oggetto non e' piu' dereferenziabile.
     *
     * @param  array<string, mixed>  $activity
     * @param  Collection<int, string>  $alreadyAddressed
     */
    private function dispatchContentToActorRelayFollowers(
        Post|Comment|Event|EventComment $object,
        array $activity,
        Collection $alreadyAddressed,
    ): void {
        $serviceActor = $this->instanceRelayActor->find();

        if ($serviceActor === null || ! $serviceActor->isApplication()) {
            return;
        }
        $destinations = $this->actorRelayFollowerDestinations($serviceActor)
            ->reject(fn (array $destination): bool => $alreadyAddressed->contains($destination['inbox_url']))
            ->values();

        if ($destinations->isEmpty()) {
            return;
        }

        if (($activity['type'] ?? null) === 'Delete') {
            $destinations->each(fn (array $destination) => $this->dispatchToInboxes(
                collect([$destination['inbox_url']]),
                $activity,
                $object->actor,
                $destination['relay_id'],
            ));

            return;
        }

        $objectUri = $this->activityObjectUri($activity);
        $activityUri = is_string($activity['id'] ?? null) ? $activity['id'] : null;

        if ($objectUri === null || $activityUri === null) {
            return;
        }

        $announce = RelayActivitySerializer::announceObject(
            $serviceActor,
            $objectUri,
            ($activity['type'] ?? null) === 'Create' ? $objectUri : $activityUri,
            is_string($activity['published'] ?? null) ? $activity['published'] : null,
        );

        $destinations->each(fn (array $destination) => $this->dispatchToInboxes(
            collect([$destination['inbox_url']]),
            $announce,
            $serviceActor,
            $destination['relay_id'],
        ));
    }

    /** @param array<string, mixed> $activity */
    private function activityObjectUri(array $activity): ?string
    {
        $object = $activity['object'] ?? null;

        if (is_string($object) && $object !== '') {
            return $object;
        }

        return is_array($object) && is_string($object['id'] ?? null) && $object['id'] !== ''
            ? $object['id']
            : null;
    }

    private function isPublicContent(Post|Comment|Event|EventComment $object): bool
    {
        return match (true) {
            $object instanceof Event => $object->visibility === Event::VISIBILITY_PUBLIC,
            $object instanceof EventComment => $object->event->visibility === Event::VISIBILITY_PUBLIC,
            $object instanceof Comment => $object->post->visibility === Post::VISIBILITY_PUBLIC,
            default => $object->visibility === Post::VISIBILITY_PUBLIC,
        };
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
     * Gli Actor Application possono seguire spontaneamente `/relay`. Quando
     * esiste anche una configurazione amministrativa, questa prevale: il
     * fan-out richiede stato accepted e pubblicazione abilitata. Per LitePub
     * la presenza in `follows` rappresenta inoltre il Follow reciproco.
     *
     * @return Collection<int, array{inbox_url: string, relay_id: ?string}>
     */
    private function actorRelayFollowerDestinations(Actor $serviceActor): Collection
    {
        $configuredRelays = Relay::query()
            ->whereIn('protocol', Relay::ACTOR_PROTOCOLS)
            ->whereNotNull('actor_uri')
            ->get()
            ->keyBy('actor_uri');

        $followerActorIds = DB::table('follows')
            ->where('following_id', $serviceActor->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->pluck('follower_id');

        if ($followerActorIds->isEmpty()) {
            return collect();
        }

        return Actor::query()
            ->whereIn('id', $followerActorIds)
            ->where('is_local', false)
            ->where('type', Actor::TYPE_APPLICATION)
            ->where('status', Actor::STATUS_ACTIVE)
            ->with('endpoints')
            ->get()
            ->filter(function (Actor $actor) use ($configuredRelays): bool {
                $relay = $configuredRelays->get($actor->uri);

                return $relay === null || $relay->publishesOutgoingActivities();
            })
            ->map(function (Actor $actor) use ($configuredRelays): array {
                $relay = $configuredRelays->get($actor->uri);

                return [
                    'inbox_url' => $actor->endpoints?->shared_inbox ?: $actor->endpoints?->inbox,
                    'relay_id' => $relay?->id,
                ];
            })
            ->filter(fn (array $destination): bool => filled($destination['inbox_url']))
            ->sortByDesc(fn (array $destination): bool => $destination['relay_id'] !== null)
            ->unique('inbox_url')
            ->values();
    }

    /**
     * @param  Collection<int, string>  $inboxUrls
     * @param  array<string, mixed>  $activity
     */
    private function dispatchToInboxes(Collection $inboxUrls, array $activity, Actor $signingActor, ?string $relayId = null): void
    {
        if (! $signingActor->isLocal() || $inboxUrls->isEmpty()) {
            return;
        }

        // LD Signature sul documento: i peer Mastodon possono inoltrare
        // l'attivita' verificando la prova indipendentemente dalla firma HTTP.
        $signingActor->loadMissing('key');

        if ($signingActor->key !== null && $signingActor->key->hasPrivateKey() && ! isset($activity['signature'])) {
            try {
                $activity = $this->linkedDataSignatures->sign($activity, $signingActor);
            } catch (Throwable $exception) {
                Log::channel('single')->warning('federation.ld_signature.sign_failed', [
                    'activity_id' => $activity['id'] ?? null,
                    'actor_id' => $signingActor->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        foreach ($inboxUrls as $inboxUrl) {
            if (app(DomainBlockManager::class)->isBlockedUrl($inboxUrl)) {
                Log::channel('single')->info('federation.delivery_skipped', [
                    'reason' => 'domain_blocked',
                    'inbox' => $inboxUrl,
                    'activity_type' => $activity['type'] ?? null,
                    'activity_id' => $activity['id'] ?? null,
                ]);

                continue;
            }

            DeliverActivityJob::dispatch($inboxUrl, $activity, $signingActor->id, $relayId)->afterCommit();

            Log::channel('single')->info('federation.delivery_queued', [
                'inbox' => $inboxUrl,
                'activity_type' => $activity['type'] ?? null,
                'activity_id' => $activity['id'] ?? null,
                'signing_actor_id' => $signingActor->id,
            ]);
        }
    }
}
