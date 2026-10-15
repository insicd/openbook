<?php

namespace App\Application\Services;

use App\Domain\Polls\LocalPollDefinition;
use App\Domain\Polls\Poll;
use App\Domain\Posts\ContentParser;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Validation\ValidationException;

final class LocalPollPublisher
{
    public function __construct(private readonly ContentParser $parser) {}

    /** @param array<string, mixed> $data */
    public function definitionFor(Actor $author, array $data): ?LocalPollDefinition
    {
        if (! isset($data['poll'])) {
            return null;
        }
        $definition = LocalPollDefinition::fromInput($data['poll']);
        if (! $author->isLocal() || ! $author->isActive() || ! $author->user?->isActive()) {
            throw ValidationException::withMessages(['poll' => __('openbook.polls.active_author')]);
        }
        if (! in_array($data['visibility'] ?? Post::VISIBILITY_PUBLIC,
            [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED, Post::VISIBILITY_FOLLOWERS], true)) {
            throw ValidationException::withMessages(['poll' => __('openbook.polls.local_posts_only')]);
        }
        if (filled($data['addressed_group_actor_id'] ?? null)
            || $this->parser->extractMentionedActors($data['body'] ?? '')
                ->contains(fn (Actor $actor): bool => $actor->isGroup() && ! $actor->isLocal())) {
            throw ValidationException::withMessages(['poll' => __('openbook.polls.local_communities_only')]);
        }

        return $definition;
    }

    public function create(Post $post, LocalPollDefinition $definition): Poll
    {
        $poll = $post->poll()->create([
            'voters_count' => 0, 'revision' => 1, 'publication_pending' => true, 'needs_delivery' => true,
            'multiple' => $definition->multiple,
            'ends_at' => $post->published_at->copy()->addSeconds($definition->durationSeconds),
            'results_updated_at' => $post->published_at,
        ]);
        foreach ($definition->options as $position => $name) {
            $poll->options()->create(['position' => $position, 'name' => $name, 'votes_count' => 0]);
        }
        $post->setRelation('poll', $poll->load('options'));

        return $poll;
    }

    /**
     * Validate the change and record its pending revision with the post already locked.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordPostUpdate(Post $post, array $data): void
    {
        if (array_key_exists('poll', $data)) {
            throw ValidationException::withMessages(['poll' => __('openbook.polls.immutable')]);
        }
        $poll = $post->poll()->lockForUpdate()->first();
        if ($poll === null) {
            return;
        }
        if (($data['visibility'] ?? $post->visibility) !== $post->visibility) {
            throw ValidationException::withMessages(['visibility' => __('openbook.polls.fixed_visibility')]);
        }
        $existingIds = $post->mentions()->pluck('actor_id');
        $added = $this->parser->extractMentionedActors($data['body'] ?? '')
            ->reject(fn (Actor $actor): bool => $actor->id === $post->actor_id || $existingIds->contains($actor->id));
        if ($added->isNotEmpty()) {
            throw ValidationException::withMessages(['body' => __('openbook.polls.fixed_audience')]);
        }
        $poll->forceFill(['revision' => $poll->revision + 1, 'needs_delivery' => true])->save();
    }
}
