<?php

namespace App\Federation\Serialization;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostBodyRenderer;
use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorUrls;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Traduce post e commenti locali nell'oggetto ActivityStreams "Note" previsto
 * dal design (sezioni 9 e 10): stesso identificatore canonico usato per la
 * pagina HTML, "inReplyTo" per i commenti, allegati "Image" per le immagini,
 * "tag" per hashtag e menzioni, "name" per il titolo del post. Un post
 * eliminato viene invece rappresentato come "Tombstone" (sezione 33).
 */
final class NoteSerializer
{
    public const PUBLIC_STREAM = 'https://www.w3.org/ns/activitystreams#Public';

    /**
     * @return array<string, mixed>
     */
    public static function forPost(Post $post): array
    {
        $post->loadMissing(['actor.endpoints', 'media', 'hashtags', 'mentions.actor', 'quotedPost', 'quotedActor', 'community.actor']);

        $actor = $post->actor;
        $uri = self::postUri($post);
        $content = self::renderContent($post->body, $post->title);

        if ($post->quotedPost !== null) {
            $quotedUri = self::postUri($post->quotedPost);
            // Fallback testuale per client che non conoscono quoteUrl: il link
            // all'originale resta leggibile anche senza supporto nativo alle citazioni.
            $content .= '<p><a href="'.e($quotedUri).'">'.e($quotedUri).'</a></p>';
        }

        if ($post->quotedActor !== null) {
            // Pagina profilo su questa istanza (non l'URI ActivityPub remoto),
            // cosi' il destinatario puo' seguire da Openbook.
            $profileUrl = $post->quotedActor->profileUrl();
            $content .= '<p><a href="'.e($profileUrl).'">'.e($profileUrl).'</a></p>';
        }

        $groupActors = self::groupActorsForPost($post);
        $content = self::appendGroupAttribution($content, $groupActors);

        $note = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $uri,
            'type' => 'Note',
            'attributedTo' => $actor->activityPubId(),
            'content' => $content,
            'url' => $uri,
            'published' => $post->published_at->toAtomString(),
            'sensitive' => $post->hasContentWarning(),
        ];

        if (filled($post->title)) {
            // Campo AS2: le altre istanze Openbook (e Lemmy/WordPress/Wafrn)
            // lo mappano su posts.title. Il <b> nel content resta per Mastodon.
            $note['name'] = $post->title;
        }

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

        if ($post->community?->is_private) {
            [$note['to'], $note['cc']] = self::privateCommunityAudience($post->community->actor);
        } else {
            [$note['to'], $note['cc']] = self::audienceForVisibility(
                $post->visibility,
                $actor,
                $post->mentions,
                $groupActors,
            );
        }

        $attachments = self::attachmentsFor($post);

        if ($attachments !== []) {
            $note['attachment'] = $attachments;
        }

        $tags = self::hashtagTagsFor($post)->concat(self::mentionTagsFor($post->mentions))->values()->all();
        $mentionedUris = collect($tags)->pluck('href')->filter()->all();

        foreach ($groupActors as $group) {
            $groupId = $group->activityPubId();

            if (in_array($groupId, $mentionedUris, true)) {
                continue;
            }

            $tags[] = [
                'type' => 'Mention',
                'href' => $groupId,
                'name' => '@'.$group->handle(),
            ];
        }

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
        $comment->loadMissing(['actor.endpoints', 'parent', 'post', 'mentions.actor', 'media']);

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
            'attributedTo' => $actor->activityPubId(),
            'inReplyTo' => $inReplyTo,
            'content' => $content,
            'url' => $uri,
            'published' => $comment->created_at->toAtomString(),
        ];

        if ($comment->wasEdited() && $comment->edited_at !== null) {
            $note['updated'] = $comment->edited_at->toAtomString();
        }

        $comment->loadMissing('post.community.actor');

        if ($comment->post->isInPrivateCommunity()) {
            [$note['to'], $note['cc']] = self::privateCommunityAudience($comment->post->community->actor);
        } else {
            $visibility = $comment->post->visibility ?? Post::VISIBILITY_PUBLIC;
            [$note['to'], $note['cc']] = self::audienceForVisibility($visibility, $actor, $comment->mentions);
        }

        $attachments = self::attachmentsFor($comment);

        if ($attachments !== []) {
            $note['attachment'] = $attachments;
        }

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
        // HTML federato: menzioni con id ActivityPub (non /attori/… locali).
        $html = (string) PostBodyRenderer::renderForFederation($body);

        if ($html === '') {
            $html = '<p></p>';
        }

        if (filled($title)) {
            // Fallback per piattaforme remote che non distinguono un titolo
            // dal corpo: viene comunque incluso nell'HTML (sezione 35).
            $html = '<p><b>'.e($title).'</b></p>'.$html;
        }

        return $html;
    }

    /**
     * Group locali (via community_id) e Group menzionati: destinazione FEP-1b12.
     *
     * @return SupportCollection<int, Actor>
     */
    private static function groupActorsForPost(Post $post): SupportCollection
    {
        $fromMentions = $post->mentions
            ->map(fn (Mention $mention) => $mention->actor)
            ->filter(fn (?Actor $actor) => $actor !== null && $actor->isGroup());

        return collect([$post->community?->actor])
            ->filter()
            ->concat($fromMentions)
            ->unique('id')
            ->values();
    }

    /**
     * Riga visibile per Mastodon & co., che non mostrano audience/tag Group:
     * menzione HTML del Group (stesso href del tag Mention, class u-url per
     * non generare la preview della community). Non viene scritta in
     * posts.body: solo nel documento federato.
     *
     * @param  SupportCollection<int, Actor>  $groups
     */
    private static function appendGroupAttribution(string $html, SupportCollection $groups): string
    {
        foreach ($groups as $group) {
            $href = $group->activityPubId();
            $handle = '@'.$group->handle();

            if ($href === '' || str_contains($html, $href) || str_contains($html, e($handle))) {
                continue;
            }

            $html .= '<p>in <a href="'.e($href).'" class="u-url mention" rel="mention">'.e($handle).'</a></p>';
        }

        return $html;
    }

    /**
     * Audience per post in community privata: Group + suoi follower, mai
     * as:Public (altrimenti Mastodon & co. trattano il contenuto come pubblico).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function privateCommunityAudience(Actor $group): array
    {
        $groupId = $group->activityPubId();
        $followersUri = $group->isLocal()
            ? LocalActorUrls::forUsername($group->preferred_username, isGroup: true)['followers']
            : $group->endpoints?->followers;

        return [
            array_values(array_filter([$groupId, $followersUri])),
            [],
        ];
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @param  iterable<int, Actor>  $groupActors
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function audienceForVisibility(
        string $visibility,
        Actor $actor,
        Collection $mentions,
        iterable $groupActors = [],
    ): array {
        $followersUri = $actor->isLocal()
            ? LocalActorUrls::forUsername($actor->preferred_username, $actor->isGroup())['followers']
            : $actor->endpoints?->followers;
        $followers = array_values(array_filter([$followersUri]));

        [$to, $cc] = match ($visibility) {
            Post::VISIBILITY_PUBLIC => [[self::PUBLIC_STREAM], $followers],
            Post::VISIBILITY_UNLISTED => [$followers, [self::PUBLIC_STREAM]],
            Post::VISIBILITY_FOLLOWERS => [$followers, []],
            Post::VISIBILITY_DIRECT => [self::mentionUrisFor($mentions), []],
            default => [[], []],
        };

        // FEP-1b12 / Friendica: indirizza esplicitamente i Group in "to".
        if ($visibility !== Post::VISIBILITY_DIRECT) {
            foreach ($groupActors as $groupActor) {
                $groupId = $groupActor->activityPubId();

                if ($groupId !== '') {
                    array_unshift($to, $groupId);
                }
            }

            $to = array_values(array_unique($to));
        }

        return [$to, $cc];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function attachmentsFor(Post|Comment $content): array
    {
        $content->loadMissing('media');

        return $content->media->map(function ($media) {
            $type = 'Image';

            if (str_starts_with($media->mime_type, 'video/') || str_starts_with($media->mime_type, 'audio/')) {
                $type = 'Document';
            }

            return [
                'type' => $type,
                'mediaType' => $media->mime_type,
                'url' => $media->url(),
                'name' => $media->alt_text ?: '',
            ];
        })->values()->all();
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
                'href' => $mention->actor->activityPubId(),
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
            ->map(fn (Mention $mention) => $mention->actor->activityPubId())
            ->values()
            ->all();
    }
}
