<?php

namespace App\Federation\Polls;

use App\Application\Services\DomainBlockManager;
use App\Domain\Posts\Post;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\NoteSerializer;

final class PollResultsDeliveryGuard
{
    public function __construct(private readonly PollResultRecipients $recipients, private readonly ActivityDelivery $delivery, private readonly DomainBlockManager $blocks) {}

    public function allows(string $postId, string $inbox, string $signerId, array $activity): bool
    {
        $post = Post::query()->whereKey($postId)->whereNull('uri')->where('status', Post::STATUS_PUBLISHED)->first();
        if ($this->blocks->isBlockedUrl($inbox) || $post === null || $post->poll === null || ! $post->actor?->isActive() || ! $post->actor->user?->isActive()) {
            return false;
        }
        $object = $activity['object'] ?? null;
        if (is_array($object)) {
            $current = NoteSerializer::forPost($post);
            if (($object['to'] ?? []) !== ($current['to'] ?? []) || ($object['cc'] ?? []) !== ($current['cc'] ?? [])) {
                return false;
            }
        }
        if ($signerId === $post->actor_id && $this->recipients->query($post, $post->poll)->where('destination', $inbox)->exists()) {
            return true;
        }

        return $this->delivery->contentRelayDestinations($post)
            ->contains(fn (array $destination): bool => $destination['inbox_url'] === $inbox && $destination['actor_id'] === $signerId);
    }
}
