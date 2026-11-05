<?php

namespace App\Application\Services;

use App\Domain\Events\Event;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Database\Eloquent\Builder;

final class PublicWebVisibility
{
    public const ALL = 'all';

    public const LOCAL = 'local';

    public const CLOSED = 'closed';

    public const MODES = [self::ALL, self::LOCAL, self::CLOSED];

    public const KEY_MODE = 'public_web_visibility';

    public const KEY_HASHTAGS = 'public_web_hashtags';

    /** Cache only on the current request, including during long-running workers/tests. */
    private function settings(): array
    {
        $request = request();
        if (! $request->attributes->has(self::class)) {
            $mode = SystemSetting::get(self::KEY_MODE) ?? self::ALL;
            $request->attributes->set(self::class, [
                'mode' => in_array($mode, self::MODES, true) ? $mode : self::CLOSED,
                'hashtags' => SystemSetting::getBool(self::KEY_HASHTAGS, true),
            ]);
        }

        return $request->attributes->get(self::class);
    }

    public function mode(): string
    {
        return $this->settings()['mode'];
    }

    public function allowsGuests(): bool
    {
        return $this->mode() !== self::CLOSED;
    }

    public function allowsHashtags(): bool
    {
        return $this->mode() === self::ALL && $this->settings()['hashtags'];
    }

    public function hashtagsEnabled(): bool
    {
        return $this->settings()['hashtags'];
    }

    public function localOnly(?Actor $viewer): bool
    {
        return $viewer === null && $this->mode() === self::LOCAL;
    }

    public function allowsActor(?Actor $actor): bool
    {
        return auth()->check() || ($this->allowsGuests() && ($this->mode() === self::ALL || $actor?->isLocal()));
    }

    public function allowsContent(Post|Event $content): bool
    {
        if (auth()->check() || $this->mode() === self::ALL) {
            return true;
        }

        return $this->allowsGuests() && $content->actor?->isLocal();
    }

    /** Restrict web lists before pagination, without altering federation scopes. */
    public function constrainContent(Builder $query): Builder
    {
        return $query->whereHas('actor', fn (Builder $actor) => $actor->where('is_local', true));
    }
}
