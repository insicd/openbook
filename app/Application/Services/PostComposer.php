<?php

namespace App\Application\Services;

use App\Domain\Communities\Community;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\ContentParser;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostAttachment;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Infrastructure\Media\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestra la pubblicazione di un post: crea la riga in "posts", carica gli
 * eventuali allegati immagine, estrae e collega hashtag/menzioni, e notifica
 * gli attori locali menzionati. Tutto in una singola transazione, cosi' un
 * upload fallito non lascia mai un post parzialmente creato. Se l'autore e'
 * locale, il post viene anche consegnato come attivita' "Create" ai
 * destinatari federati appropriati (sezione {@see ActivityDelivery::deliverContent()}).
 * Una modifica ({@see self::update()}) ricalcola hashtag/menzioni, marca
 * {@see Post::$edited_at} e consegna un "Update" alla stessa audience.
 * Una citazione (quoted_post_id) conta anche come condivisione sul post
 * originale via {@see AnnounceManager}: stesso contatore della share diretta,
 * senza una seconda notifica "ha condiviso" (resta solo TYPE_QUOTE).
 *
 * Post verso una community locale (Fase 5 / FEP-1b12): il Group locale
 * ritrasmette con Announce ai propri follower senza notificare l'autore.
 * Verso una community remota: menzione + audience + consegna Create all'inbox
 * del Group (il server remoto ritrasmette se l'autore e' membro).
 */
final class PostComposer
{
    public function __construct(
        private readonly MediaUploader $mediaUploader,
        private readonly ContentParser $contentParser,
        private readonly NotificationCreator $notificationCreator,
        private readonly ActivityDelivery $delivery,
        private readonly AnnounceManager $announceManager,
        private readonly FollowManager $followManager,
    ) {}

    /**
     * @param  array{title?: ?string, content_warning?: ?string, body: string, visibility?: string, language?: ?string, quoted_post_id?: ?string, community_id?: ?string, addressed_group_actor_id?: ?string, images?: array<int, UploadedFile>, alt_texts?: array<int, ?string>}  $data
     */
    public function compose(Actor $author, array $data): Post
    {
        $images = $data['images'] ?? [];
        $maxAttachments = (int) config('openbook.media.max_attachments_per_post');

        if (count($images) > $maxAttachments) {
            throw new InvalidArgumentException("Puoi allegare al massimo {$maxAttachments} file per post.");
        }

        $quotedPost = $this->resolveQuotedPost($author, $data['quoted_post_id'] ?? null);
        $community = $this->resolveCommunity($author, $data['community_id'] ?? null);
        $addressedGroup = $this->resolveAddressedGroup($author, $data['addressed_group_actor_id'] ?? null);

        if ($community !== null && $addressedGroup !== null) {
            throw new InvalidArgumentException(__('openbook.communities.errors.addressed_and_local'));
        }

        $post = DB::transaction(function () use ($author, $data, $images, $quotedPost, $community, $addressedGroup) {
            $post = Post::query()->create([
                'actor_id' => $author->id,
                'community_id' => $community?->id,
                'quoted_post_id' => $quotedPost?->id,
                'title' => $data['title'] ?? null,
                'content_warning' => $data['content_warning'] ?? null,
                'body' => $data['body'],
                'language' => $data['language'] ?? null,
                // Anche se il composer manda "public", i post in community
                // privata restano chiusi via Post::scopeVisibleTo e non
                // vengono federati come as:Public (NoteSerializer / delivery).
                'visibility' => $data['visibility'] ?? Post::VISIBILITY_PUBLIC,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $altTexts = $data['alt_texts'] ?? [];

            foreach (array_values($images) as $position => $image) {
                $media = $this->mediaUploader->store($image, $author, $altTexts[$position] ?? null);

                PostAttachment::query()->create([
                    'post_id' => $post->id,
                    'media_id' => $media->id,
                    'position' => $position,
                ]);
            }

            $this->attachHashtags($post);
            $this->attachMentions($post, $author);

            if ($addressedGroup !== null) {
                $this->ensureMention($post, $addressedGroup);
            }

            if ($quotedPost !== null) {
                $this->notificationCreator->notify(
                    $quotedPost->actor,
                    Notification::TYPE_QUOTE,
                    $author,
                    $post,
                );
            }

            if ($community !== null) {
                $community->increment('posts_count');
            }

            return $post;
        });

        if ($quotedPost !== null) {
            $this->announceManager->announce($author, $quotedPost, notify: false, direct: false);
        }

        if ($community !== null) {
            $community->loadMissing('actor');
            $this->announceManager->announce($community->actor, $post, notify: false, direct: false);
            $this->notifyCommunityMembers($community, $author, $post);
        }

        if ($author->isLocal()) {
            $post->load(['mentions.actor', 'quotedPost', 'community.actor']);

            // Community privata: niente Create ai follower dell'autore.
            // I membri remoti del Group ricevono l'Announce del Group.
            if (! ($community?->is_private)) {
                $extraTargets = $addressedGroup !== null ? [$addressedGroup] : [];
                $this->delivery->deliverContent($post, ActivitySerializer::create($post), $extraTargets);
            }
        }

        return $post;
    }

    /**
     * Aggiorna un post locale dell'autore: testo, titolo, avviso, visibilita'
     * e eventuali nuovi allegati (gli esistenti restano). Community, citazione
     * e conversazione non si cambiano. Dopo il salvataggio si federa un
     * Update verso la stessa audience del Create.
     *
     * @param  array{title?: ?string, content_warning?: ?string, body: string, visibility?: string, language?: ?string, images?: array<int, UploadedFile>, alt_texts?: array<int, ?string>}  $data
     */
    public function update(Actor $author, Post $post, array $data): Post
    {
        if ($post->actor_id !== $author->id) {
            throw new InvalidArgumentException('Puoi modificare solo i tuoi post.');
        }

        if ($post->isRemote() || ! $post->isPublished() || $post->isDirectMessage()) {
            throw new InvalidArgumentException('Questo post non puo\' essere modificato.');
        }

        $post->loadMissing(['media', 'mentions.actor', 'community.actor']);

        $images = $data['images'] ?? [];
        $maxAttachments = (int) config('openbook.media.max_attachments_per_post');
        $existingCount = $post->media->count();

        if ($existingCount + count($images) > $maxAttachments) {
            throw new InvalidArgumentException("Puoi allegare al massimo {$maxAttachments} file per post.");
        }

        $post = DB::transaction(function () use ($author, $post, $data, $images, $existingCount) {
            $post->update([
                'title' => $data['title'] ?? null,
                'content_warning' => $data['content_warning'] ?? null,
                'body' => $data['body'],
                'language' => $data['language'] ?? $post->language,
                'visibility' => $data['visibility'] ?? $post->visibility,
                'edited_at' => now(),
            ]);

            $altTexts = $data['alt_texts'] ?? [];
            $nextPosition = $existingCount;

            foreach (array_values($images) as $offset => $image) {
                $media = $this->mediaUploader->store($image, $author, $altTexts[$offset] ?? null);

                PostAttachment::query()->create([
                    'post_id' => $post->id,
                    'media_id' => $media->id,
                    'position' => $nextPosition + $offset,
                ]);
            }

            $this->attachHashtags($post);
            $this->syncMentions($post, $author);

            return $post->fresh() ?? $post;
        });

        if ($author->isLocal()) {
            $post->load(['mentions.actor', 'quotedPost', 'community.actor', 'media']);

            $addressedGroup = $post->mentions
                ->map(fn ($mention) => $mention->actor)
                ->first(fn (?Actor $actor) => $actor !== null && $actor->isGroup() && ! $actor->isLocal());

            $extraTargets = $addressedGroup !== null ? [$addressedGroup] : [];
            $this->delivery->deliverContent($post, ActivitySerializer::update($post), $extraTargets);
        }

        return $post;
    }

    /**
     * Avvisa i membri locali (Follow accettato verso il Group) che e' uscito
     * un nuovo post. Remoti e autore sono esclusi: le notifiche in-app sono
     * solo locali e NotificationCreator ignora gia' l'auto-notifica.
     */
    private function notifyCommunityMembers(Community $community, Actor $author, Post $post): void
    {
        $members = Actor::query()
            ->where('is_local', true)
            ->where('type', Actor::TYPE_PERSON)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereKeyNot($author->id)
            ->whereIn('id', Follow::query()
                ->select('follower_id')
                ->where('following_id', $community->actor_id)
                ->where('status', Follow::STATUS_ACCEPTED))
            ->get();

        foreach ($members as $member) {
            $this->notificationCreator->notify(
                $member,
                Notification::TYPE_COMMUNITY_POST,
                $author,
                $post,
            );
        }
    }

    private function resolveCommunity(Actor $author, ?string $communityId): ?Community
    {
        if ($communityId === null || $communityId === '') {
            return null;
        }

        $community = Community::query()->with('actor')->whereKey($communityId)->first();

        if ($community === null) {
            throw new InvalidArgumentException(__('openbook.communities.errors.not_found'));
        }

        $isOwner = $author->user_id !== null && $community->owner_user_id === $author->user_id;

        if (! $isOwner && ! $community->isMember($author)) {
            throw new InvalidArgumentException(__('openbook.communities.errors.not_a_member'));
        }

        return $community;
    }

    private function resolveAddressedGroup(Actor $author, ?string $actorId): ?Actor
    {
        if ($actorId === null || $actorId === '') {
            return null;
        }

        $group = Actor::query()->with('endpoints')->whereKey($actorId)->first();

        if ($group === null || ! $group->isGroup() || ! $group->isActive()) {
            throw new InvalidArgumentException(__('openbook.communities.errors.remote_group_not_found'));
        }

        if ($group->isLocal()) {
            throw new InvalidArgumentException(__('openbook.communities.errors.use_local_community'));
        }

        if (! $this->followManager->isFollowing($author, $group)) {
            throw new InvalidArgumentException(__('openbook.communities.errors.not_a_member'));
        }

        return $group;
    }

    private function resolveQuotedPost(Actor $author, ?string $quotedPostId): ?Post
    {
        if ($quotedPostId === null || $quotedPostId === '') {
            return null;
        }

        $quoted = Post::query()
            ->with('actor')
            ->whereKey($quotedPostId)
            ->where('status', Post::STATUS_PUBLISHED)
            ->visibleTo($author)
            ->first();

        if ($quoted === null) {
            throw new InvalidArgumentException('Il post da citare non esiste o non e\' visibile.');
        }

        return $quoted;
    }

    private function attachHashtags(Post $post): void
    {
        $names = $this->contentParser->extractHashtagNames($post->body);

        $hashtagIds = $names->map(function (string $name) {
            return Hashtag::query()->firstOrCreate(['name' => $name])->id;
        });

        $post->hashtags()->sync($hashtagIds);
    }

    private function attachMentions(Post $post, Actor $author): void
    {
        $this->syncMentions($post, $author, notifyNew: true);
    }

    /**
     * Allinea le menzioni al testo attuale. I Group gia' collegati (community
     * remota) restano anche se non compaiono nel body. Le menzioni nuove
     * notificano; quelle rimosse dal testo vengono cancellate.
     */
    private function syncMentions(Post $post, Actor $author, bool $notifyNew = true): void
    {
        $mentionedActors = $this->contentParser->extractMentionedActors($post->body)
            ->filter(fn (Actor $actor) => $actor->id !== $author->id)
            ->unique('id')
            ->values();

        $keepIds = $mentionedActors->pluck('id');

        $protectedGroupIds = $post->mentions
            ->map(fn ($mention) => $mention->actor)
            ->filter(fn (?Actor $actor) => $actor !== null && $actor->isGroup())
            ->pluck('id');

        $keepIds = $keepIds->concat($protectedGroupIds)->unique()->values();

        $alreadyIds = $post->mentions->pluck('actor_id');

        foreach ($mentionedActors as $actor) {
            $isNew = ! $alreadyIds->contains($actor->id);
            $this->ensureMention($post, $actor);

            if ($notifyNew && $isNew && $actor->isLocal() && $actor->isPerson()) {
                $this->notificationCreator->notify($actor, Notification::TYPE_MENTION, $author, $post);
            }
        }

        Mention::query()
            ->where('mentionable_type', $post->getMorphClass())
            ->where('mentionable_id', $post->id)
            ->whereNotIn('actor_id', $keepIds)
            ->delete();
    }

    private function ensureMention(Post $post, Actor $actor): void
    {
        $exists = Mention::query()
            ->where('mentionable_type', $post->getMorphClass())
            ->where('mentionable_id', $post->id)
            ->where('actor_id', $actor->id)
            ->exists();

        if ($exists) {
            return;
        }

        Mention::query()->create([
            'mentionable_type' => $post->getMorphClass(),
            'mentionable_id' => $post->id,
            'actor_id' => $actor->id,
        ]);
    }
}
