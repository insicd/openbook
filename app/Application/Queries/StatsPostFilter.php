<?php

namespace App\Application\Queries;

use App\Application\Services\DomainBlockManager;
use Illuminate\Database\Query\Builder;

/** Shared eligibility for live corpus queries and new historical contributions. */
final class StatsPostFilter
{
    public function __construct(private readonly DomainBlockManager $domainBlocks) {}

    public function posts(Builder $query): Builder
    {
        $this->authors($query);
        $query->where('p.status', 'published')->where('p.visibility', 'public')->whereNull('p.conversation_id')
            ->where(function (Builder $query): void {
                $query->whereNull('p.community_id')->orWhereExists(function (Builder $community): void {
                    $community->selectRaw('1')->from('communities as c')->whereColumn('c.id', 'p.community_id')->where('c.is_private', false);
                });
            });
        if (config('openbook.moderation.hide_content_warnings_from_world', false)) {
            $query->where(fn (Builder $query) => $query->whereNull('p.content_warning')->orWhere('p.content_warning', ''));
            $this->excludeForcedTags($query, 'post_hashtags', 'post_id', 'p.id');
        }

        return $query;
    }

    public function authors(Builder $query): Builder
    {
        $query->where('a.status', 'active')->where('a.remote_suspended', false)->whereNull('a.deleted_at');
        $blocked = $this->domainBlocks->blockedHosts();
        $query->whereNotIn('a.domain', $blocked);
        foreach ($blocked as $host) {
            foreach (['https://', 'http://'] as $scheme) {
                $origin = $scheme.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $host);
                $query->where('a.uri', '<>', $scheme.$host);
                foreach (['/', ':', '?', '#'] as $boundary) {
                    $query->where('a.uri', 'not like', $origin.$boundary.'%');
                }
            }
        }

        return $query;
    }

    public function excludeForcedTags(Builder $query, string $pivot, string $foreignKey, string $contentId): void
    {
        $tags = config('openbook.moderation.forced_content_warning_hashtags', []);
        if (! is_array($tags) || $tags === []) {
            return;
        }
        $query->whereNotExists(function (Builder $query) use ($pivot, $foreignKey, $contentId, $tags): void {
            $query->selectRaw('1')->from($pivot.' as ph')->join('hashtags as h', 'h.id', '=', 'ph.hashtag_id')
                ->whereColumn('ph.'.$foreignKey, $contentId)->whereIn('h.name', $tags);
        });
    }
}
