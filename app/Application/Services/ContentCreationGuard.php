<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Exceptions\EmailVerificationRequired;
use App\Federation\Actors\Actor;

/** Only local user-authored content requires a verified email. */
final class ContentCreationGuard
{
    public static function allows(User|Actor|null $author): bool
    {
        if ($author instanceof Actor) {
            if (! $author->isLocal() || ! $author->isPerson()) {
                return true;
            }

            // An Actor may retain a User loaded before email confirmation.
            $author = $author->user()->first();
        }

        return $author?->hasVerifiedEmail() ?? false;
    }

    public static function ensureAllowed(User|Actor $author): void
    {
        if (! self::allows($author)) {
            throw new EmailVerificationRequired;
        }
    }
}
