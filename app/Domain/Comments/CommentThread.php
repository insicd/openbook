<?php

namespace App\Domain\Comments;

use Illuminate\Support\Collection;

/**
 * Albero dei commenti di un post: una query, raggruppamento per padre.
 * La profondita' visiva (un solo livello di indentazione) e' decisa in
 * vista: qui si conserva l'accoppiamento padre/figlio completo.
 */
final class CommentThread
{
    /**
     * @param  Collection<int, Comment>  $comments
     * @return list<array{comment: Comment, children: list<array{comment: Comment, children: list<mixed>}>}>
     */
    public static function tree(Collection $comments): array
    {
        $byParent = $comments->groupBy(fn (Comment $comment) => $comment->parent_comment_id ?? 'root');

        $build = function (string $parentKey) use (&$build, $byParent): array {
            return $byParent->get($parentKey, collect())
                ->map(fn (Comment $comment) => [
                    'comment' => $comment,
                    'children' => $build($comment->id),
                ])
                ->all();
        };

        return $build('root');
    }

    /**
     * @param  list<array{comment: Comment, children: list<mixed>}>  $tree
     * @return array{comment: Comment, children: list<mixed>}|null
     */
    public static function findNode(array $tree, string $commentId): ?array
    {
        foreach ($tree as $node) {
            if ($node['comment']->id === $commentId) {
                return $node;
            }

            $found = self::findNode($node['children'], $commentId);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Catena dei padri pubblicati, dal piu' vicino al post al diretto.
     *
     * @param  Collection<string, Comment>  $byId
     * @return list<Comment>
     */
    public static function publishedAncestors(Comment $comment, Collection $byId): array
    {
        $chain = [];
        $currentId = $comment->parent_comment_id;
        $guard = 0;

        while ($currentId !== null && $guard < 50) {
            $guard++;
            $parent = $byId->get($currentId);

            if ($parent === null) {
                break;
            }

            if ($parent->isPublished()) {
                array_unshift($chain, $parent);
            }

            $currentId = $parent->parent_comment_id;
        }

        return $chain;
    }
}
