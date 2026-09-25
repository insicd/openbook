<?php

namespace App\Federation\Serialization;

use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostBodyRenderer;
use App\Domain\SocialGraph\Follow;
use Illuminate\Support\Carbon;

/** Public Mastodon-compatible account representation for instance metadata. */
final class MastodonAccountSerializer
{
    /** @return array<string, mixed> */
    public static function serialize(User $user): array
    {
        $user->loadMissing(['profile', 'actor']);
        $actor = $user->actor;
        $profile = $user->profile;
        $avatar = $profile?->avatarUrl() ?? url('/favicon.ico');
        $header = $profile?->coverUrl() ?? url('/favicon.ico');
        $posts = Post::query()->where('actor_id', $actor->id)
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('visibility', Post::VISIBILITY_PUBLIC);
        $lastStatus = (clone $posts)->max('published_at');

        return [
            'id' => $user->id,
            'username' => $user->username,
            'acct' => $user->username,
            'url' => route('profile.show', $user->username),
            'uri' => $actor->activityPubId(),
            'display_name' => $profile?->display_name ?: $user->username,
            'note' => (string) PostBodyRenderer::renderForFederation((string) ($profile?->bio ?? '')),
            'avatar' => $avatar,
            'avatar_static' => $avatar,
            'header' => $header,
            'header_static' => $header,
            'locked' => $actor->manually_approves_followers,
            'fields' => array_map(static fn (array $link): array => [
                'name' => (string) ($link['label'] ?? $link['url']),
                'value' => '<a href="'.e($link['url']).'" rel="me nofollow noopener" target="_blank">'.e($link['url']).'</a>',
                'verified_at' => null,
            ], $profile?->links ?? []),
            'emojis' => [],
            'bot' => false,
            'group' => false,
            'discoverable' => $actor->discoverable,
            'created_at' => $user->created_at?->toIso8601String(),
            'last_status_at' => $lastStatus === null ? null : Carbon::parse($lastStatus)->toDateString(),
            'statuses_count' => (clone $posts)->count(),
            'followers_count' => $actor->followerRelations()->where('status', Follow::STATUS_ACCEPTED)->count(),
            'following_count' => $actor->followedRelations()->where('status', Follow::STATUS_ACCEPTED)->count(),
        ];
    }
}
