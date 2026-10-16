<?php

namespace App\Federation\Polls;

use App\Application\Services\DomainBlockManager;
use App\Domain\Polls\Poll;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PollResultRecipients
{
    public function __construct(private readonly DomainBlockManager $blocks) {}

    /** Indexed source branches; deduplicate shared inboxes before applying the cursor. */
    public function query(Post $post, Poll $poll): Builder
    {
        $following = $post->isInPrivateCommunity() ? $post->community->actor_id : $post->actor_id;
        $followers = $this->actors(DB::table('follows')->join('actors', 'actors.id', '=', 'follows.follower_id'), $post)
            ->where('follows.following_id', $following)->where('follows.status', Follow::STATUS_ACCEPTED);
        $voters = $this->actors(DB::table('poll_votes')->join('actors', 'actors.id', '=', 'poll_votes.actor_id'), $post)
            ->where('poll_votes.poll_id', $poll->id);
        $mentions = $this->actors(DB::table('mentions')->join('actors', 'actors.id', '=', 'mentions.actor_id'), $post)
            ->where('mentions.mentionable_type', 'post')->where('mentions.mentionable_id', $post->id);

        $sources = $followers->unionAll($voters)->unionAll($mentions);
        if ($post->community !== null && ! $post->community->is_private) {
            $members = $this->actors(DB::table('follows')->join('actors', 'actors.id', '=', 'follows.follower_id'), $post)
                ->where('follows.following_id', $post->community->actor_id)->where('follows.status', Follow::STATUS_ACCEPTED);
            $sources->unionAll($members);
        }

        return DB::query()->fromSub($sources, 'recipients')->distinct();
    }

    private function actors(Builder $query, Post $post): Builder
    {
        $fallback = DB::getDriverName() === 'sqlite' ? "'actor:' || actors.id" : "CONCAT('actor:', actors.id)";
        $inbox = "COALESCE(NULLIF(actor_endpoints.shared_inbox, ''), NULLIF(actor_endpoints.inbox, ''))";
        $collation = DB::getDriverName() === 'sqlite' ? '' : ' COLLATE utf8mb4_bin';
        $visible = Post::query()->selectRaw('1')->whereKey($post->id)->visibleToActorId(DB::raw('actors.id'));

        return $query->leftJoin('actor_endpoints', 'actor_endpoints.actor_id', '=', 'actors.id')
            ->where('actors.is_local', false)->where('actors.status', Actor::STATUS_ACTIVE)->where('actors.remote_suspended', false)
            ->whereNotIn('actors.domain', $this->blocks->blockedHosts())
            ->whereExists($visible->toBase())
            ->selectRaw("COALESCE($inbox, $fallback)$collation as destination")
            ->selectRaw("CASE WHEN $inbox IS NULL THEN actors.id ELSE NULL END as recipient_actor_id");
    }
}
