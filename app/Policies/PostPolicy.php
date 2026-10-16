<?php

namespace App\Policies;

use App\Application\Services\DomainBlockManager;
use App\Application\Services\RemotePollInteractionPolicy;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;

class PostPolicy
{
    public function vote(User $user, Post $post): bool
    {
        $actor = $user->actor;
        $poll = $post->remotePoll;
        if ($actor === null || ! app(RemotePollInteractionPolicy::class)->allows($actor, $post)
            || $poll === null || $poll->isClosed()
            || $poll->options->count() < 2 || ! $actor->key?->hasPrivateKey()) {
            return false;
        }
        $inbox = $post->actor->deliveryInboxUrl();
        $blocks = app(DomainBlockManager::class);

        return filled($inbox) && ! $blocks->isBlockedUrl($inbox);
    }

    public function update(User $user, Post $post): bool
    {
        if ($post->isRemote() || ! $post->isPublished() || $post->isDirectMessage()) {
            return false;
        }

        return $post->actor?->user_id === $user->id;
    }

    public function delete(User $user, Post $post): bool
    {
        // I post remoti sono una cache locale di Note altrui: non si
        // "eliminano" da Openbook (andrebbero eventualmente solo
        // invalidati dalla federazione). Solo l'autore di un post locale,
        // o un amministratore, puo' cancellarlo.
        if ($post->isRemote()) {
            return false;
        }

        return $post->actor->user_id === $user->id || $user->is_admin;
    }

    /**
     * Segnalazione locale (non federata): qualsiasi utente autenticato puo'
     * segnalare un post altrui, locale o remoto, se ancora pubblicato.
     */
    public function report(User $user, Post $post): bool
    {
        if ($post->status !== Post::STATUS_PUBLISHED) {
            return false;
        }

        return $post->actor?->user_id !== $user->id;
    }
}
