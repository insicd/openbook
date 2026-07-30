<?php

namespace App\Application\Services;

use App\Domain\Notifications\Notification;
use App\Domain\Posts\ContentParser;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostAttachment;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Infrastructure\Media\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestra la pubblicazione di un post: crea la riga in "posts", carica gli
 * eventuali allegati immagine, estrae e collega hashtag/menzioni, e notifica
 * gli attori locali menzionati. Tutto in una singola transazione, cosi' un
 * upload fallito non lascia mai un post parzialmente creato. Se l'autore e'
 * locale, il post viene anche consegnato come attivita' "Create" ai
 * destinatari federati appropriati (sezione {@see ActivityDelivery::deliverContent()}).
 * Una citazione (quoted_post_id) conta anche come condivisione sul post
 * originale via {@see AnnounceManager}: stesso contatore della share diretta,
 * senza una seconda notifica "ha condiviso" (resta solo TYPE_QUOTE).
 */
final class PostComposer
{
    public function __construct(
        private readonly MediaUploader $mediaUploader,
        private readonly ContentParser $contentParser,
        private readonly NotificationCreator $notificationCreator,
        private readonly ActivityDelivery $delivery,
        private readonly AnnounceManager $announceManager,
    ) {}

    /**
     * @param  array{title?: ?string, content_warning?: ?string, body: string, visibility?: string, language?: ?string, quoted_post_id?: ?string, images?: array<int, UploadedFile>, alt_texts?: array<int, ?string>}  $data
     */
    public function compose(Actor $author, array $data): Post
    {
        $images = $data['images'] ?? [];
        $maxAttachments = (int) config('openbook.media.max_attachments_per_post');

        if (count($images) > $maxAttachments) {
            throw new InvalidArgumentException("Puoi allegare al massimo {$maxAttachments} immagini per post.");
        }

        $quotedPost = $this->resolveQuotedPost($author, $data['quoted_post_id'] ?? null);

        $post = DB::transaction(function () use ($author, $data, $images, $quotedPost) {
            $post = Post::query()->create([
                'actor_id' => $author->id,
                'quoted_post_id' => $quotedPost?->id,
                'title' => $data['title'] ?? null,
                'content_warning' => $data['content_warning'] ?? null,
                'body' => $data['body'],
                'language' => $data['language'] ?? null,
                'visibility' => $data['visibility'] ?? Post::VISIBILITY_PUBLIC,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $altTexts = $data['alt_texts'] ?? [];

            foreach (array_values($images) as $position => $image) {
                $media = $this->mediaUploader->store($image, $author, $altTexts[$position] ?? null);

                PostAttachment::query()->create([
                    'post_id' => $post->id,
                    'media_id' => $media->id,
                    'position' => $position,
                ]);
            }

            $this->attachHashtags($post);
            $this->attachMentions($post, $author);

            if ($quotedPost !== null) {
                $this->notificationCreator->notify(
                    $quotedPost->actor,
                    Notification::TYPE_QUOTE,
                    $author,
                    $post,
                );
            }

            return $post;
        });

        if ($quotedPost !== null) {
            // Stesso contatore/Announce della share diretta; notify=false perche'
            // l'autore ha gia' ricevuto TYPE_QUOTE qui sopra.
            $this->announceManager->announce($author, $quotedPost, notify: false);
        }

        if ($author->isLocal()) {
            $post->load(['mentions.actor', 'quotedPost']);
            $this->delivery->deliverContent($post, ActivitySerializer::create($post));
        }

        return $post;
    }

    /**
     * Il post citato deve esistere, essere pubblicato e visibile a chi cita
     * (stesse regole del feed: non si puo' citare un post followers-only di
     * qualcuno che non si segue, ne' un post eliminato).
     */
    private function resolveQuotedPost(Actor $author, ?string $quotedPostId): ?Post
    {
        if ($quotedPostId === null || $quotedPostId === '') {
            return null;
        }

        $quoted = Post::query()
            ->with('actor')
            ->whereKey($quotedPostId)
            ->where('status', Post::STATUS_PUBLISHED)
            ->visibleTo($author)
            ->first();

        if ($quoted === null) {
            throw new InvalidArgumentException('Il post da citare non esiste o non e\' visibile.');
        }

        return $quoted;
    }

    private function attachHashtags(Post $post): void
    {
        $names = $this->contentParser->extractHashtagNames($post->body);

        if ($names->isEmpty()) {
            return;
        }

        $hashtagIds = $names->map(function (string $name) {
            return Hashtag::query()->firstOrCreate(['name' => $name])->id;
        });

        $post->hashtags()->sync($hashtagIds);
    }

    private function attachMentions(Post $post, Actor $author): void
    {
        $mentionedActors = $this->contentParser->extractLocalMentionedActors($post->body);

        foreach ($mentionedActors as $actor) {
            if ($actor->id === $author->id) {
                continue;
            }

            Mention::query()->create([
                'mentionable_type' => $post->getMorphClass(),
                'mentionable_id' => $post->id,
                'actor_id' => $actor->id,
            ]);

            $this->notificationCreator->notify($actor, Notification::TYPE_MENTION, $author, $post);
        }
    }
}
