<?php

namespace App\Application\Queries;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stream di attivita' di un Actor, per quanto questa istanza ne sa:
 * commenti (anche risposte) e condivisioni dirette. I mi piace, i post
 * propri e i follow restano fuori: i post stanno nel tab dedicato, i like
 * rumorebbero lo stream. Ogni riga e' inclusa solo se il contenuto
 * bersaglio e' visibile al visitatore (stesso {@see Post::scopeVisibleTo()}
 * del feed). Non e' l'outbox ActivityPub completo: i commenti remoti su
 * contenuti mai arrivati qui restano sconosciuti.
 */
final class ActorActivityQuery
{
    public function forActor(Actor $actor, ?Actor $viewer, ?Request $request = null): ActivityPage
    {
        $request ??= request();
        $perPage = max(1, (int) config('openbook.profile.activity_per_page', 20));
        $page = max(1, (int) $request->integer('page', 1));
        $offset = ($page - 1) * $perPage;
        $fetchLimit = $offset + $perPage + 1;

        $candidates = collect()
            ->concat($this->commentCandidates($actor, $viewer, $fetchLimit))
            ->concat($this->announceCandidates($actor, $viewer, $fetchLimit));

        $sorted = $candidates
            ->sort(function (array $left, array $right): int {
                $time = $right['occurred_at']->getTimestamp() <=> $left['occurred_at']->getTimestamp();

                if ($time !== 0) {
                    return $time;
                }

                $type = $left['type'] <=> $right['type'];

                if ($type !== 0) {
                    return $type;
                }

                return $right['id'] <=> $left['id'];
            })
            ->values();

        $slice = $sorted->slice($offset, $perPage + 1)->values();
        $hasMore = $slice->count() > $perPage;

        if ($hasMore) {
            $slice = $slice->take($perPage)->values();
        }

        $items = $this->hydrate($actor, $slice);

        $nextPageUrl = null;

        if ($hasMore && $items->isNotEmpty()) {
            $queryParams = array_merge($request->except(['cursor']), [
                'page' => $page + 1,
            ]);
            $nextPageUrl = $request->url().'?'.http_build_query($queryParams);
        }

        return new ActivityPage($items, $nextPageUrl);
    }

    /**
     * @return Collection<int, array{type: string, id: string, occurred_at: Carbon}>
     */
    private function commentCandidates(Actor $actor, ?Actor $viewer, int $limit): Collection
    {
        return Comment::query()
            ->where('actor_id', $actor->id)
            ->where('status', Comment::STATUS_PUBLISHED)
            ->whereHas('post', fn (Builder $query) => $this->constrainVisiblePost($query, $viewer))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'created_at'])
            ->map(fn (Comment $comment): array => $this->candidate(
                ActorActivityItem::TYPE_COMMENT,
                $comment->id,
                $comment->created_at,
            ));
    }

    /**
     * @return Collection<int, array{type: string, id: string, occurred_at: Carbon}>
     */
    private function announceCandidates(Actor $actor, ?Actor $viewer, int $limit): Collection
    {
        return Announce::query()
            ->where('actor_id', $actor->id)
            ->where('is_direct', true)
            ->whereHas('post', fn (Builder $query) => $this->constrainVisiblePost($query, $viewer))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'created_at'])
            ->map(fn (Announce $announce): array => $this->candidate(
                ActorActivityItem::TYPE_ANNOUNCE,
                $announce->id,
                $announce->created_at,
            ));
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private function constrainVisiblePost(Builder $query, ?Actor $viewer): Builder
    {
        return $query
            ->where('status', Post::STATUS_PUBLISHED)
            ->excludingPrivateMessages()
            ->visibleTo($viewer);
    }

    /**
     * @return array{type: string, id: string, occurred_at: Carbon}
     */
    private function candidate(string $type, string $id, mixed $occurredAt): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'occurred_at' => Carbon::parse($occurredAt),
        ];
    }

    /**
     * @param  Collection<int, array{type: string, id: string, occurred_at: Carbon}>  $rows
     * @return Collection<int, ActorActivityItem>
     */
    private function hydrate(Actor $actor, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $grouped = $rows->groupBy('type');

        $comments = $this->loadComments($grouped->get(ActorActivityItem::TYPE_COMMENT, collect())->pluck('id'));
        $announces = $this->loadAnnounces($grouped->get(ActorActivityItem::TYPE_ANNOUNCE, collect())->pluck('id'));

        $actor->loadMissing('user.profile');

        return $rows
            ->map(function (array $row) use ($actor, $comments, $announces): ?ActorActivityItem {
                return match ($row['type']) {
                    ActorActivityItem::TYPE_COMMENT => $this->itemFromComment($actor, $row, $comments->get($row['id'])),
                    ActorActivityItem::TYPE_ANNOUNCE => $this->itemFromAnnounce($actor, $row, $announces->get($row['id'])),
                    default => null,
                };
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $ids
     * @return Collection<string, Comment>
     */
    private function loadComments(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return Comment::query()
            ->with(['actor.user.profile', 'post.actor.user.profile', 'parent.actor.user.profile'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, string>  $ids
     * @return Collection<string, Announce>
     */
    private function loadAnnounces(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return Announce::query()
            ->with(['post.actor.user.profile'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array{type: string, id: string, occurred_at: Carbon}  $row
     */
    private function itemFromComment(Actor $actor, array $row, ?Comment $comment): ?ActorActivityItem
    {
        if ($comment === null || $comment->post === null) {
            return null;
        }

        $target = $comment->isReply()
            ? ($comment->parent?->actor ?? $comment->post->actor)
            : $comment->post->actor;

        return new ActorActivityItem(
            type: ActorActivityItem::TYPE_COMMENT,
            id: $row['id'],
            occurredAt: $row['occurred_at'],
            actor: $actor,
            post: $comment->post,
            comment: $comment,
            targetActor: $target,
        );
    }

    /**
     * @param  array{type: string, id: string, occurred_at: Carbon}  $row
     */
    private function itemFromAnnounce(Actor $actor, array $row, ?Announce $announce): ?ActorActivityItem
    {
        if ($announce === null || $announce->post === null) {
            return null;
        }

        return new ActorActivityItem(
            type: ActorActivityItem::TYPE_ANNOUNCE,
            id: $row['id'],
            occurredAt: $row['occurred_at'],
            actor: $actor,
            post: $announce->post,
            announce: $announce,
            targetActor: $announce->post->actor,
        );
    }
}
