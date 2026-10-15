<?php

namespace App\Application\Queries;

use App\Domain\Polls\PollOption;
use App\Domain\Polls\PollVoteChoice;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;

/** Author-only controller supplies a validated option of a local Question. */
final class PollVotersQuery
{
    public const PER_PAGE = 25;

    public function identifiable(PollOption $option): Builder
    {
        return $this->choices($option)
            ->join('actors as voters', 'voters.id', '=', 'ballots.actor_id')
            ->where('voters.status', '!=', Actor::STATUS_DELETED)
            ->where(fn (Builder $query): Builder => $query->where('voters.is_local', false)->orWhereNotNull('voters.user_id'))
            ->select('poll_vote_choices.*')->with('vote.actor.user.profile')
            ->orderBy('poll_vote_choices.id');
    }

    public function total(PollOption $option): int
    {
        return $this->choices($option)->count();
    }

    private function choices(PollOption $option): Builder
    {
        return PollVoteChoice::query()->where('poll_vote_choices.option_id', $option->id)
            ->join('poll_votes as ballots', 'ballots.id', '=', 'poll_vote_choices.vote_id')
            ->where('ballots.poll_id', $option->poll_id);
    }
}
