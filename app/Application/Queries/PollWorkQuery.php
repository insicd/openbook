<?php

namespace App\Application\Queries;

use App\Domain\Accounts\User;
use App\Domain\Polls\Poll;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;

final class PollWorkQuery
{
    public function expired(): Builder
    {
        return Poll::query()->whereNull('closed_at')->where('ends_at', '<=', now())
            ->whereHas('post', fn (Builder $post): Builder => $this->localPosts($post))
            ->orderBy('ends_at')->orderBy('id');
    }

    public function pending(): Builder
    {
        return Poll::query()->where('needs_delivery', true)
            ->whereHas('post', fn (Builder $post): Builder => $this->localPosts($post)
                ->whereHas('actor', fn (Builder $actor): Builder => $actor->where('status', Actor::STATUS_ACTIVE)
                    ->whereHas('user', fn (Builder $user): Builder => $user->where('status', User::STATUS_ACTIVE))))
            ->orderBy('id');
    }

    private function localPosts(Builder $posts): Builder
    {
        return $posts->whereNull('uri')->where('status', Post::STATUS_PUBLISHED);
    }
}
