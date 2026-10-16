<?php

namespace App\Application\Services;

use App\Domain\Polls\LocalPollDefinition;
use App\Domain\Polls\Poll;
use App\Domain\Polls\PollVote;
use App\Domain\Polls\PollVoteChoice;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Collects local and federated ballots for Questions owned by this instance. */
final class LocalPollVoter
{
    public function __construct(private readonly LocalPollInteractionPolicy $interactionPolicy) {}

    /** @param list<string> $optionIds */
    public function vote(Actor $voter, Poll $poll, array $optionIds, string $optionsVersion): PollVote
    {
        if (! $voter->isLocal()) {
            throw new AuthorizationException;
        }

        return $this->record($voter, $poll, $optionIds, $optionsVersion, null, null, null);
    }

    /** The inbox adapter supplies the authenticated payload and its original receipt. */
    public function receive(Actor $voter, Poll $poll, string $name, string $uri, Carbon $receivedAt): ?PollVote
    {
        if ($voter->isLocal()) {
            throw new AuthorizationException;
        }
        try {
            return $this->record($voter, $poll, [], null, $name, $uri, $receivedAt);
        } catch (UniqueConstraintViolationException $exception) {
            // Different polls can race for one remote Note ID; the unique key decides.
            if (PollVoteChoice::query()->where('remote_uri_hash', hash('sha256', $uri))->exists()) {
                return null;
            }
            throw $exception;
        }
    }

    /** @param list<string> $optionIds */
    private function record(Actor $voter, Poll $poll, array $optionIds, ?string $optionsVersion, ?string $name, ?string $uri, ?Carbon $receivedAt): ?PollVote
    {
        return DB::transaction(function () use ($voter, $poll, $optionIds, $optionsVersion, $name, $uri, $receivedAt): ?PollVote {
            $post = Post::query()->whereKey($poll->post_id)->whereNull('uri')->whereNull('conversation_id')
                ->where('status', Post::STATUS_PUBLISHED)->visibleTo($voter)->lockForUpdate()->firstOrFail();
            $voter = $voter->fresh(['user']);
            $post->load('actor.user');
            if ($voter === null || ! $this->interactionPolicy->allows($voter, $post)) {
                throw new AuthorizationException;
            }
            $locked = Poll::query()->whereKey($poll->id)->where('post_id', $post->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('post', $post);
            $locked->setRelation('options', $locked->options()->lockForUpdate()->get());
            $vote = $locked->votes()->where('voter_hash', PollVote::identityHash($voter->uri))->lockForUpdate()->first();
            if ($uri === null && $vote !== null) {
                return $vote->load('choices');
            }

            $acceptedAt = $receivedAt ?? now();
            // A queued, timely remote ballot remains valid after scheduled closure.
            if ($locked->ends_at === null || $acceptedAt->gte($locked->ends_at)
                || $acceptedAt->lt($post->published_at) || $acceptedAt->gt(now())
                || ($uri === null && $locked->isClosed())) {
                throw ValidationException::withMessages(['poll' => __('openbook.polls.closed')]);
            }
            if ($uri !== null) {
                $option = $locked->options->first(fn ($option): bool => $option->name === $name);
                if ($option === null) {
                    return null;
                }
                $optionIds = [$option->id];
                $identity = PollVoteChoice::query()->where('remote_uri_hash', hash('sha256', $uri))->first();
                if ($identity !== null) {
                    return $identity->vote_id === $vote?->id && $identity->option_id === $option->id
                        ? $vote->load('choices') : null;
                }
                if ($vote !== null) {
                    if ($vote->choices()->where('option_id', $option->id)->exists()) {
                        return $vote->load('choices');
                    }
                    if (! $locked->multiple) {
                        return null;
                    }
                }
            } else {
                if (! array_is_list($optionIds) || count($optionIds) < 1 || count($optionIds) > LocalPollDefinition::MAX_OPTIONS
                    || count(array_filter($optionIds, 'is_string')) !== count($optionIds)
                    || count(array_unique($optionIds)) !== count($optionIds)
                    || (! $locked->multiple && count($optionIds) !== 1)
                    || count(array_intersect($optionIds, $locked->options->modelKeys())) !== count($optionIds)) {
                    throw ValidationException::withMessages(['choices' => __('openbook.polls.choose')]);
                }
                if ($optionsVersion === null || ! hash_equals($locked->optionsVersion(), $optionsVersion)) {
                    throw ValidationException::withMessages(['choices' => __('openbook.polls.changed')]);
                }
            }

            if ($vote === null) {
                $vote = $locked->votes()->create(['actor_id' => $voter->id, 'created_at' => $acceptedAt]);
                $locked->voters_count++;
            }
            foreach ($optionIds as $id) {
                $option = $locked->options->find($id);
                $vote->choices()->create(['option_id' => $id, 'created_at' => $acceptedAt,
                    'remote_uri_hash' => $uri !== null ? hash('sha256', $uri) : null]);
                $option->increment('votes_count');
            }
            $locked->forceFill(['revision' => $locked->revision + 1, 'results_updated_at' => now(), 'needs_delivery' => true])->save();

            return $vote->load('choices');
        }, 3);
    }
}
