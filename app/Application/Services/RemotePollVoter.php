<?php

namespace App\Application\Services;

use App\Domain\Polls\RemotePoll;
use App\Domain\Polls\RemotePollVote;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Polls\RemotePollDocument;
use App\Federation\Serialization\PollVoteSerializer;
use App\Infrastructure\Security\Http\SsrfGuard;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemotePollVoter
{
    public function __construct(
        private readonly ActivityDelivery $delivery,
        private readonly DomainBlockManager $blocks,
        private readonly SsrfGuard $ssrf,
    ) {}

    /** @param list<string> $optionIds */
    public function vote(Actor $voter, RemotePoll $poll, array $optionIds, string $optionsVersion): RemotePollVote
    {
        $voter = $this->activeVoter($voter);
        $poll = RemotePoll::query()->findOrFail($poll->id);
        $post = Post::query()->whereKey($poll->post_id)->where('status', Post::STATUS_PUBLISHED)->visibleTo($voter)->firstOrFail();
        $this->assertTarget($voter, $post);
        $existing = $poll->votes()->where('actor_id', $voter->id)->first();
        if ($existing !== null) {
            return $existing->load('choices');
        }

        if (! array_is_list($optionIds) || count($optionIds) < 1 || count($optionIds) > RemotePollDocument::MAX_OPTIONS
            || count(array_filter($optionIds, 'is_string')) !== count($optionIds)
            || count(array_unique($optionIds)) !== count($optionIds)) {
            throw ValidationException::withMessages(['choices' => __('openbook.polls.choose')]);
        }

        // Endpoint resolution and DNS happen before any database locks.
        $inbox = $this->delivery->remoteActorInbox($post->actor);
        $voter->loadMissing('key');
        if (blank($inbox) || $voter->key === null || ! $voter->key->hasPrivateKey()) {
            throw ValidationException::withMessages(['poll' => __('openbook.polls.retry')]);
        }
        $this->assertInbox($inbox);
        try {
            $this->ssrf->assertUrlIsSafe($inbox);
        } catch (SsrfViolationException) {
            throw ValidationException::withMessages(['poll' => __('openbook.polls.retry')]);
        }

        return DB::transaction(function () use ($voter, $poll, $optionIds, $optionsVersion, $inbox): RemotePollVote {
            $post = Post::query()->whereKey($poll->post_id)->where('status', Post::STATUS_PUBLISHED)->visibleTo($voter)->lockForUpdate()->firstOrFail();
            $locked = RemotePoll::query()->whereKey($poll->id)->where('post_id', $post->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('options', $locked->options()->lockForUpdate()->get());
            $voter = $this->activeVoter($voter);
            $this->assertTarget($voter, $post);
            $existing = $locked->votes()->where('actor_id', $voter->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing->load('choices');
            }
            if ($locked->isClosed()) {
                throw ValidationException::withMessages(['poll' => __('openbook.polls.closed')]);
            }
            if (! hash_equals($locked->optionsVersion(), $optionsVersion)) {
                throw ValidationException::withMessages(['choices' => __('openbook.polls.changed')]);
            }
            $options = $locked->options->keyBy('id');
            if ((! $locked->multiple && count($optionIds) !== 1)
                || count($options) < 2 || count(array_intersect($optionIds, $options->keys()->all())) !== count($optionIds)) {
                throw ValidationException::withMessages(['choices' => __('openbook.polls.choose')]);
            }
            $currentInbox = $post->actor->endpoints?->shared_inbox ?: $post->actor->endpoints?->inbox;
            $this->assertInbox($inbox);
            $voter->load('key');
            if ($currentInbox !== $inbox || $voter->key === null || ! $voter->key->hasPrivateKey()) {
                throw ValidationException::withMessages(['poll' => __('openbook.polls.retry')]);
            }

            $vote = $locked->votes()->create(['actor_id' => $voter->id]);
            foreach ($optionIds as $id) {
                $option = $options[$id];
                $choice = $vote->choices()->create(['position' => $option->position, 'name' => $option->name]);
                $this->delivery->enqueueDirectInTransaction(
                    $voter, $inbox, PollVoteSerializer::create($choice, $voter, $post), $choice->id,
                );
            }

            return $vote->load('choices');
        }, 3);
    }

    private function activeVoter(Actor $actor): Actor
    {
        $actor = $actor->fresh(['user']);
        if ($actor === null || ! $actor->isLocal() || ! $actor->isActive() || ! $actor->user?->isActive()) {
            throw new AuthorizationException(__('openbook.polls.sign_in'));
        }

        return $actor;
    }

    private function assertTarget(Actor $voter, Post $post): void
    {
        $post->load('actor.endpoints');
        RemoteInteractionGuard::assertAllowed($voter, $post->actor);
        if (! $post->isRemote() || ! $post->actor->isActive()
            || $this->blocks->isBlockedUrl($post->uri ?? '') || $this->blocks->isBlockedUrl($post->actor->uri)) {
            throw new AuthorizationException(__('openbook.polls.retry'));
        }
    }

    private function assertInbox(string $inbox): void
    {
        if ($this->blocks->isBlockedUrl($inbox)) {
            throw new AuthorizationException(__('openbook.polls.retry'));
        }
    }
}
