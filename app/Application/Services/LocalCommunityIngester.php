<?php

namespace App\Application\Services;

use App\Domain\Communities\Community;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Ingresso FEP-1b12 verso un Group locale: un Create remoto che cita o
 * indirizza la community viene agganciato a {@see Post::$community_id} e
 * ritrasmesso con Announce del Group, come i post scritti dai membri locali.
 */
final class LocalCommunityIngester
{
    public function __construct(
        private readonly AnnounceManager $announceManager,
        private readonly NotificationCreator $notificationCreator,
    ) {}

    /**
     * @param  array<string, mixed>  $note
     */
    public function attachRemotePost(
        Post $post,
        Actor $author,
        array $note,
        ?Actor $inboxTarget = null,
        bool $fromRelay = false,
    ): void {
        if ($post->community_id !== null || $post->isDirectMessage() || $author->isRemotelySuspended()) {
            return;
        }

        $community = $this->resolveCommunity($note, $author, $inboxTarget, $fromRelay);

        if ($community === null) {
            return;
        }

        $allowedVisibilities = $community->is_private
            ? [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED, Post::VISIBILITY_FOLLOWERS, Post::VISIBILITY_DIRECT]
            : [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED];

        if (! in_array($post->visibility, $allowedVisibilities, true)) {
            return;
        }

        $attached = DB::transaction(function () use ($post, $community): bool {
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->first();

            if ($locked === null || $locked->community_id !== null) {
                return false;
            }

            $attributes = ['community_id' => $community->id];

            // Come i post locali: la privacy la fa scopeVisibleTo sulla
            // community, non la visibility ActivityPub del Create in arrivo.
            if ($community->is_private) {
                $attributes['visibility'] = Post::VISIBILITY_PUBLIC;
            }

            $locked->forceFill($attributes)->save();
            $community->increment('posts_count');
            $post->forceFill($attributes);
            $post->setRelation('community', $community);

            return true;
        });

        if (! $attached) {
            return;
        }

        try {
            $community->loadMissing('actor');
            $post->loadMissing('actor.endpoints');
            $this->announceManager->announce($community->actor, $post, notify: false, direct: false);
        } catch (AuthorizationException) {
            return;
        }

        $this->notifyMembers($community, $author, $post);
    }

    public function notifyMembers(Community $community, Actor $author, Post $post): void
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

    /**
     * @param  array<string, mixed>  $note
     */
    private function resolveCommunity(array $note, Actor $author, ?Actor $inboxTarget, bool $fromRelay): ?Community
    {
        $addressedUris = $this->audienceUris($note);
        $mentionUris = $this->mentionUris($note);
        $candidateUris = array_values(array_unique([...$addressedUris, ...$mentionUris]));

        if ($inboxTarget !== null && $inboxTarget->isLocal() && $inboxTarget->isGroup()) {
            array_unshift($candidateUris, $inboxTarget->uri);
            $candidateUris = array_values(array_unique($candidateUris));
        }

        if ($candidateUris === []) {
            return null;
        }

        $groups = Actor::query()
            ->with('community')
            ->where('is_local', true)
            ->where('type', Actor::TYPE_GROUP)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereIn('uri', $candidateUris)
            ->get()
            ->keyBy('uri');

        foreach ($candidateUris as $uri) {
            $group = $groups->get($uri);

            if ($group === null) {
                continue;
            }

            $community = $group->community;

            if ($community === null) {
                continue;
            }

            if ($this->authorMayPost($community, $author, $fromRelay)) {
                return $community;
            }
        }

        return null;
    }

    private function authorMayPost(Community $community, Actor $author, bool $fromRelay): bool
    {
        if ($community->is_private || $fromRelay) {
            return $community->isMember($author);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $note
     * @return list<string>
     */
    private function audienceUris(array $note): array
    {
        $uris = [];

        foreach (['to', 'cc', 'audience'] as $field) {
            foreach ($this->flattenAudience($note[$field] ?? null) as $uri) {
                if ($this->isActorUri($uri)) {
                    $uris[] = $uri;
                }
            }
        }

        return $uris;
    }

    /**
     * @param  array<string, mixed>  $note
     * @return list<string>
     */
    private function mentionUris(array $note): array
    {
        $uris = [];
        $tags = is_array($note['tag'] ?? null) ? $note['tag'] : [];

        foreach ($tags as $tag) {
            if (! is_array($tag) || ($tag['type'] ?? null) !== 'Mention' || ! is_string($tag['href'] ?? null)) {
                continue;
            }

            if ($this->isActorUri($tag['href'])) {
                $uris[] = $tag['href'];
            }
        }

        return $uris;
    }

    /**
     * @return list<string>
     */
    private function flattenAudience(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $uris = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $uris[] = $item;
            }
        }

        return $uris;
    }

    private function isActorUri(string $uri): bool
    {
        if (in_array($uri, [
            'https://www.w3.org/ns/activitystreams#Public',
            'as:Public',
            'Public',
        ], true)) {
            return false;
        }

        return ! str_ends_with($uri, '/followers');
    }
}
