<?php

namespace App\Federation\Serialization;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostBodyRenderer;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Traduce post e commenti locali nell'oggetto ActivityStreams "Note" previsto
 * dal design (sezioni 9 e 10): stesso identificatore canonico usato per la
 * pagina HTML, "inReplyTo" per i commenti, allegati "Image" per le immagini,
 * "tag" per hashtag e menzioni. Un post eliminato viene invece rappresentato
 * come "Tombstone" (sezione 33).
 */
final class NoteSerializer
{
    public const PUBLIC_STREAM = 'https://www.w3.org/ns/activitystreams#Public';

    /**
     * @return array<string, mixed>
     */
    public static function forPost(Post $post): array
    {
        $post->loadMissing(['actor.endpoints', 'media', 'hashtags', 'mentions.actor', 'quotedPost']);

        $actor = $post->actor;
        $uri = self::postUri($post);
        $content = self::renderContent($post->body, $post->title);

        if ($post->quotedPost !== null) {
            $quotedUri = self::postUri($post->quotedPost);
            // Fallback testuale per client che non conoscono quoteUrl: il link
            // all'originale resta leggibile anche senza supporto nativo alle citazioni.
            $content .= '<p><a href="'.e($quotedUri).'">'.e($quotedUri).'</a></p>';
        }

        $note = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $uri,
            'type' => 'Note',
            'attributedTo' => $actor->uri,
            'content' => $content,
            'url' => $uri,
            'published' => $post->published_at->toAtomString(),
            'sensitive' => $post->hasContentWarning(),
        ];

        if ($post->quotedPost !== null) {
            $note['quoteUrl'] = self::postUri($post->quotedPost);
        }

        if ($post->hasContentWarning()) {
            $note['summary'] = e((string) $post->content_warning);
        }

        if ($post->wasEdited() && $post->edited_at !== null) {
            $note['updated'] = $post->edited_at->toAtomString();
        }

        if (filled($post->language)) {
            $note['contentMap'] = [$post->language => $content];
        }

        [$note['to'], $note['cc']] = self::audienceForVisibility($post->visibility, $actor, $post->mentions);

        $attachments = self::attachmentsFor($post);

        if ($attachments !== []) {
            $note['attachment'] = $attachments;
        }

        $tags = self::hashtagTagsFor($post)->concat(self::mentionTagsFor($post->mentions))->values()->all();

        if ($tags !== []) {
            $note['tag'] = $tags;
        }

        return $note;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forComment(Comment $comment): array
    {
        $comment->loadMissing(['actor.endpoints', 'parent', 'post', 'mentions.actor']);

        $actor = $comment->actor;
        $uri = self::commentUri($comment);

        $inReplyTo = $comment->parent !== null
            ? self::commentUri($comment->parent)
            : self::postUri($comment->post);

        $content = self::renderContent($comment->body, null);

        $note = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $uri,
            'type' => 'Note',
            'attributedTo' => $actor->uri,
            'inReplyTo' => $inReplyTo,
            'content' => $content,
            'url' => $uri,
            'published' => $comment->created_at->toAtomString(),
        ];

        if ($comment->wasEdited() && $comment->edited_at !== null) {
            $note['updated'] = $comment->edited_at->toAtomString();
        }

        $visibility = $comment->post->visibility ?? Post::VISIBILITY_PUBLIC;
        [$note['to'], $note['cc']] = self::audienceForVisibility($visibility, $actor, $comment->mentions);

        $tags = self::mentionTagsFor($comment->mentions)->values()->all();

        if ($tags !== []) {
            $note['tag'] = $tags;
        }

        return $note;
    }

    /**
     * @return array<string, mixed>
     */
    public static function tombstoneForPost(Post $post): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => self::postUri($post),
            'type' => 'Tombstone',
            'formerType' => 'Note',
            'deleted' => $post->updated_at->toAtomString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tombstoneForComment(Comment $comment): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => self::commentUri($comment),
            'type' => 'Tombstone',
            'formerType' => 'Note',
            'deleted' => $comment->updated_at->toAtomString(),
        ];
    }

    public static function postUri(Post $post): string
    {
        return $post->uri ?? url("/posts/{$post->id}");
    }

    public static function commentUri(Comment $comment): string
    {
        return $comment->uri ?? url("/comments/{$comment->id}");
    }

    /**
     * Identificatore ActivityPub di un post o commento, locale o remoto in
     * cache: usato ovunque serva riferirsi al suo "object" (Like, Announce,
     * inReplyTo) senza distinguere esplicitamente i due casi.
     */
    public static function uriFor(Post|Comment $object): string
    {
        return $object instanceof Post ? self::postUri($object) : self::commentUri($object);
    }

    private static function renderContent(string $body, ?string $title): string
    {
        $html = '<p>'.((string) PostBodyRenderer::render($body)).'</p>';

        if (filled($title)) {
            // Fallback per piattaforme remote che non distinguono un titolo
            // dal corpo: viene comunque incluso nell'HTML (sezione 35).
            $html = '<p><b>'.e($title).'</b></p>'.$html;
        }

        return $html;
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function audienceForVisibility(string $visibility, Actor $actor, Collection $mentions): array
    {
        $followersUri = $actor->endpoints?->followers;
        $followers = array_values(array_filter([$followersUri]));

        return match ($visibility) {
            Post::VISIBILITY_PUBLIC => [[self::PUBLIC_STREAM], $followers],
            Post::VISIBILITY_UNLISTED => [$followers, [self::PUBLIC_STREAM]],
            Post::VISIBILITY_FOLLOWERS => [$followers, []],
            Post::VISIBILITY_DIRECT => [self::mentionUrisFor($mentions), []],
            default => [[], []],
        };
    }

    /**
     * @return list<array<string, string>>
     */
    private static function attachmentsFor(Post $post): array
    {
        return $post->media->map(fn ($media) => [
            'type' => 'Image',
            'mediaType' => $media->mime_type,
            'url' => $media->url(),
            'name' => $media->alt_text ?: '',
        ])->values()->all();
    }

    /**
     * @return SupportCollection<int, array<string, string>>
     */
    private static function hashtagTagsFor(Post $post): SupportCollection
    {
        return $post->hashtags->map(fn ($hashtag) => [
            'type' => 'Hashtag',
            'href' => route('hashtags.show', $hashtag->name),
            'name' => '#'.$hashtag->name,
        ]);
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return SupportCollection<int, array<string, string>>
     */
    private static function mentionTagsFor(Collection $mentions): SupportCollection
    {
        return $mentions->filter(fn (Mention $mention) => $mention->actor !== null)
            ->map(fn (Mention $mention) => [
                'type' => 'Mention',
                'href' => $mention->actor->uri,
                'name' => '@'.$mention->actor->handle(),
            ]);
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return list<string>
     */
    private static function mentionUrisFor(Collection $mentions): array
    {
        return $mentions->filter(fn (Mention $mention) => $mention->actor !== null)
            ->map(fn (Mention $mention) => $mention->actor->uri)
            ->values()
            ->all();
    }
}
