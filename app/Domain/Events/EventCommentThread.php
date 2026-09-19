<?php

namespace App\Domain\Events;

use Illuminate\Support\Collection;

final class EventCommentThread
{
    /**
     * @param  Collection<int, EventComment>  $comments
     * @return list<array{comment: EventComment, children: list<mixed>}>
     */
    public static function tree(Collection $comments): array
    {
        $byParent = $comments->groupBy(fn (EventComment $comment) => $comment->parent_event_comment_id ?? 'root');

        $build = function (string $parentKey) use (&$build, $byParent): array {
            return $byParent->get($parentKey, collect())
                ->map(fn (EventComment $comment) => [
                    'comment' => $comment,
                    'children' => $build($comment->id),
                ])
                ->all();
        };

        return $build('root');
    }
}
