<?php

namespace App\Application\Queries;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Una riga dello stream di attivita' di un profilo: commento/risposta o
 * condivisione, con bersaglio visibile al visitatore, link ed estratto.
 */
final class ActorActivityItem
{
    public const TYPE_COMMENT = 'comment';

    public const TYPE_ANNOUNCE = 'announce';

    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly Carbon $occurredAt,
        public readonly Actor $actor,
        public readonly ?Post $post = null,
        public readonly ?Comment $comment = null,
        public readonly ?Actor $targetActor = null,
        public readonly ?Announce $announce = null,
    ) {}

    public function targetUrl(): ?string
    {
        return match ($this->type) {
            self::TYPE_ANNOUNCE => $this->post !== null
                ? route('posts.show', $this->post)
                : null,
            self::TYPE_COMMENT => $this->comment !== null
                ? route('comments.show', $this->comment)
                : null,
            default => null,
        };
    }

    public function excerpt(): ?string
    {
        $source = match ($this->type) {
            self::TYPE_ANNOUNCE => $this->postText(),
            self::TYPE_COMMENT => $this->comment?->body,
            default => null,
        };

        $text = trim(preg_replace('/\s+/u', ' ', (string) $source) ?? '');

        if ($text === '') {
            return null;
        }

        $length = (int) config('openbook.feed.body_excerpt_length', 150);

        return Str::limit($text, $length);
    }

    public function iconName(): string
    {
        return match ($this->type) {
            self::TYPE_COMMENT => 'comment',
            self::TYPE_ANNOUNCE => 'share',
            default => 'comment',
        };
    }

    public function messageHtml(): string
    {
        $nameToken = '%%NAME%%';
        $authorToken = '%%AUTHOR%%';

        $html = e(__('openbook.profile.activity.'.$this->verbKey(), [
            'name' => $nameToken,
            'author' => $authorToken,
        ]));

        $html = str_replace(e($nameToken), $this->linkedName($this->actor), $html);

        if (str_contains($html, e($authorToken))) {
            $html = str_replace(
                e($authorToken),
                $this->linkedName($this->targetActor),
                $html,
            );
        }

        return $html;
    }

    private function verbKey(): string
    {
        return match ($this->type) {
            self::TYPE_COMMENT => $this->comment?->isReply() ? 'replied' : 'commented',
            self::TYPE_ANNOUNCE => 'shared',
            default => 'commented',
        };
    }

    private function postText(): string
    {
        if ($this->post === null) {
            return '';
        }

        $title = trim((string) $this->post->title);

        if ($title !== '') {
            return $title;
        }

        return (string) $this->post->body;
    }

    private function linkedName(?Actor $actor): string
    {
        $name = $actor?->displayName() ?: __('openbook.notifications.someone');
        $escaped = e($name);
        $url = $actor?->profileUrl();

        if ($url === null) {
            return $escaped;
        }

        return '<a href="'.e($url).'" class="ob-activity__name">'.$escaped.'</a>';
    }
}
