<?php

namespace App\Federation\Serialization;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Domain\Reactions\Like;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorUrls;

/**
 * Costruisce le attivita' ActivityStreams che Openbook invia verso altri
 * server (Fase 4): Follow/Accept/Reject/Undo per il grafo sociale,
 * Create/Delete per post e commenti, Like/Announce/Undo per le reazioni.
 *
 * Gli identificatori delle attivita' sono sempre *derivati* dalla riga di
 * dominio che le origina (id della riga in "follows"/"likes"/"announces",
 * oppure identificatore del post/commento), cosi' da non dover introdurre
 * una tabella "activities" separata solo per assegnare un id univoco:
 * qualunque server ricevente puo' comunque referenziarle in un successivo
 * Accept/Reject/Undo.
 */
final class ActivitySerializer
{
    private const CONTEXT = 'https://www.w3.org/ns/activitystreams';

    public static function followActivityUri(Follow $follow): string
    {
        return url("/activities/follows/{$follow->id}");
    }

    /**
     * @return array<string, mixed>
     */
    public static function follow(Follow $follow): array
    {
        // "to" allineato a Lemmy / FEP-1b12: molti Group lo usano per
        // indirizzare la richiesta; se assente restano comunque validi.
        return [
            '@context' => self::CONTEXT,
            'id' => self::followActivityUri($follow),
            'type' => 'Follow',
            'actor' => $follow->follower->activityPubId(),
            'object' => $follow->following->activityPubId(),
            'to' => [$follow->following->activityPubId()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function accept(Follow $follow): array
    {
        // "to" allineato a Lemmy / FEP-1b12: senza destinatario esplicito
        // molte istanze (soprattutto Lemmy) ignorano l'Accept e la join
        // resta "in attesa" sul lato remoto.
        return [
            '@context' => self::CONTEXT,
            'id' => self::followActivityUri($follow).'/accetta',
            'type' => 'Accept',
            'actor' => $follow->following->activityPubId(),
            'object' => self::embeddedFollow($follow),
            'to' => [$follow->follower->activityPubId()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function reject(Follow $follow): array
    {
        return [
            '@context' => self::CONTEXT,
            'id' => self::followActivityUri($follow).'/rifiuta',
            'type' => 'Reject',
            'actor' => $follow->following->activityPubId(),
            'object' => self::embeddedFollow($follow),
            'to' => [$follow->follower->activityPubId()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function undoFollow(Follow $follow): array
    {
        return [
            '@context' => self::CONTEXT,
            'id' => self::followActivityUri($follow).'/annulla',
            'type' => 'Undo',
            'actor' => $follow->follower->activityPubId(),
            'object' => self::follow($follow),
        ];
    }

    /**
     * Rappresentazione del Follow originale usata come "object" di Accept e
     * Reject: se la riga e' nata da una richiesta remota in ingresso, usa
     * l'id dell'attivita' cosi' come ricevuta (necessario perche' il
     * richiedente possa far corrispondere la risposta), altrimenti (caso
     * teorico: accettazione manuale di un follow locale-locale mai federato)
     * ricade sull'id derivato.
     *
     * @return array<string, mixed>
     */
    private static function embeddedFollow(Follow $follow): array
    {
        return [
            'id' => $follow->remote_activity_uri ?? self::followActivityUri($follow),
            'type' => 'Follow',
            'actor' => $follow->follower->activityPubId(),
            'object' => $follow->following->activityPubId(),
            'to' => [$follow->following->activityPubId()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function like(Like $like, Post|Comment $target): array
    {
        return [
            '@context' => self::CONTEXT,
            'id' => url("/activities/likes/{$like->id}"),
            'type' => 'Like',
            'actor' => $like->actor->activityPubId(),
            'object' => NoteSerializer::uriFor($target),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function undoLike(Like $like, Post|Comment $target): array
    {
        return [
            '@context' => self::CONTEXT,
            'id' => url("/activities/likes/{$like->id}/annulla"),
            'type' => 'Undo',
            'actor' => $like->actor->activityPubId(),
            'object' => self::like($like, $target),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function announce(Announce $announce, Post $post): array
    {
        $sharer = $announce->actor;
        $post->loadMissing('community.actor');

        if ($post->community?->is_private) {
            $followersUri = $sharer->isLocal()
                ? LocalActorUrls::forUsername($sharer->preferred_username, $sharer->isGroup())['followers']
                : $sharer->endpoints?->followers;

            return [
                '@context' => self::CONTEXT,
                'id' => url("/activities/announces/{$announce->id}"),
                'type' => 'Announce',
                'actor' => $sharer->activityPubId(),
                'published' => $announce->created_at->toAtomString(),
                'to' => array_values(array_filter([$followersUri])),
                'cc' => array_values(array_filter([
                    $post->actor->activityPubId(),
                    $post->community->actor?->activityPubId(),
                ])),
                'object' => NoteSerializer::uriFor($post),
            ];
        }

        return [
            '@context' => self::CONTEXT,
            'id' => url("/activities/announces/{$announce->id}"),
            'type' => 'Announce',
            'actor' => $sharer->activityPubId(),
            'published' => $announce->created_at->toAtomString(),
            'to' => [NoteSerializer::PUBLIC_STREAM],
            'cc' => array_values(array_unique(array_filter([
                $sharer->isLocal()
                    ? LocalActorUrls::forUsername($sharer->preferred_username, $sharer->isGroup())['followers']
                    : $sharer->endpoints?->followers,
                $post->actor->activityPubId(),
            ]))),
            'object' => NoteSerializer::uriFor($post),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function undoAnnounce(Announce $announce, Post $post): array
    {
        return [
            '@context' => self::CONTEXT,
            'id' => url("/activities/announces/{$announce->id}/annulla"),
            'type' => 'Undo',
            'actor' => $announce->actor->activityPubId(),
            'object' => self::announce($announce, $post),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function create(Post|Comment $object): array
    {
        $note = $object instanceof Post ? NoteSerializer::forPost($object) : NoteSerializer::forComment($object);

        return [
            '@context' => self::CONTEXT,
            'id' => $note['id'].'/attivita',
            'type' => 'Create',
            'actor' => $note['attributedTo'],
            'published' => $note['published'],
            'to' => $note['to'] ?? [],
            'cc' => $note['cc'] ?? [],
            'object' => $note,
        ];
    }

    /**
     * Notifica un cambio al profilo pubblico di un Actor locale (nome,
     * biografia, link, avatar, copertina, account protetto): l'"object" e'
     * lo stesso documento Person completo restituito dall'endpoint canonico
     * dell'Actor, cosi' chi riceve puo' applicarlo direttamente senza dover
     * rifare una richiesta HTTP aggiuntiva. A differenza di Follow/Like/
     * Announce non esiste una riga di dominio dedicata da cui derivare un id
     * stabile, quindi ne viene generato uno nuovo a ogni chiamata: non serve
     * essere referenziabile da un successivo Accept/Undo come per le altre
     * attivita'.
     *
     * @return array<string, mixed>
     */
    public static function updateActor(Actor $actor): array
    {
        $object = ActorSerializer::serialize($actor);

        return [
            '@context' => self::CONTEXT,
            'id' => $object['id'].'/aggiornamenti/'.now()->getTimestampMs(),
            'type' => 'Update',
            'actor' => $object['id'],
            'to' => [NoteSerializer::PUBLIC_STREAM],
            'object' => $object,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(Post|Comment $object): array
    {
        $tombstone = $object instanceof Post
            ? NoteSerializer::tombstoneForPost($object)
            : NoteSerializer::tombstoneForComment($object);

        return [
            '@context' => self::CONTEXT,
            'id' => $tombstone['id'].'/elimina',
            'type' => 'Delete',
            'actor' => $object->actor->activityPubId(),
            'to' => [NoteSerializer::PUBLIC_STREAM],
            'object' => $tombstone,
        ];
    }
}
