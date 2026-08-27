<?php

namespace App\Federation\Posts;

use App\Domain\Posts\Post;
use App\Federation\Inbox\RemotePostObject;

/**
 * Aggiorna i contatori likes/announces di un post remoto con i
 * {@code totalItems} delle collection ActivityPub {@code likes} e
 * {@code shares} del server di origine.
 *
 * Non si pagina l'elenco (puo' essere enorme): solo il numero, cosi' le
 * card del feed mostrano un conteggio realistico senza GET extra per ogni
 * riga. Il conteggio gia' noto in locale resta un pavimento: un Mi piace
 * appena messo su questa istanza non scompare se l'origine non l'ha ancora
 * contato.
 */
final class RemoteReactionCountSync
{
    /**
     * @param  array<string, mixed>  $note
     */
    public static function applyFromNote(Post $post, array $note): void
    {
        self::apply(
            $post,
            RemotePostObject::collectionTotalItems($note['likes'] ?? null),
            RemotePostObject::collectionTotalItems($note['shares'] ?? null),
        );
    }

    public static function apply(Post $post, ?int $likesTotal, ?int $sharesTotal): void
    {
        $updates = [];

        if ($likesTotal !== null) {
            $next = max($likesTotal, (int) $post->likes_count);

            if ($next !== (int) $post->likes_count) {
                $updates['likes_count'] = $next;
            }
        }

        if ($sharesTotal !== null) {
            $next = max($sharesTotal, (int) $post->announces_count);

            if ($next !== (int) $post->announces_count) {
                $updates['announces_count'] = $next;
            }
        }

        if ($updates === []) {
            return;
        }

        $post->forceFill($updates)->saveQuietly();
    }
}
