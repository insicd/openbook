<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Communities\Community;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\ActorEndpoint;
use App\Federation\Actors\ActorKey;
use App\Federation\Actors\LocalActorUrls;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crea una community locale: Actor Group + chiavi/endpoint + riga di dominio.
 * Il proprietario viene iscritto automaticamente (Follow accepted).
 */
final class CommunityRegistrar
{
    public function __construct(
        private readonly RsaKeyPairGenerator $keyPairGenerator,
    ) {}

    /**
     * @param  array{slug: string, name: string, summary?: ?string, is_private?: bool}  $data
     */
    public function register(User $owner, array $data): Community
    {
        $slug = mb_strtolower($data['slug']);
        $domain = (string) config('openbook.domain');

        $this->assertSlugAvailable($slug);

        return DB::transaction(function () use ($owner, $data, $slug, $domain): Community {
            $urls = LocalActorUrls::forUsername($slug, isGroup: true);
            $isPrivate = (bool) ($data['is_private'] ?? false);

            $actor = Actor::query()->create([
                'user_id' => null,
                'type' => Actor::TYPE_GROUP,
                'is_local' => true,
                'preferred_username' => $slug,
                'domain' => $domain,
                'uri' => $urls['uri'],
                'name' => $data['name'],
                'summary' => $data['summary'] ?? null,
                'manually_approves_followers' => $isPrivate,
                'status' => Actor::STATUS_ACTIVE,
            ]);

            $keyPair = $this->keyPairGenerator->generate((int) config('openbook.actor_key_bits', 2048));

            ActorKey::query()->create([
                'actor_id' => $actor->id,
                'public_key' => $keyPair->publicKey,
                'private_key' => $keyPair->privateKey,
            ]);

            ActorEndpoint::query()->create([
                'actor_id' => $actor->id,
                'inbox' => $urls['inbox'],
                'outbox' => $urls['outbox'],
                'followers' => $urls['followers'],
                'following' => $urls['following'],
                'shared_inbox' => $urls['shared_inbox'],
            ]);

            $community = Community::query()->create([
                'actor_id' => $actor->id,
                'owner_user_id' => $owner->id,
                'slug' => $slug,
                'is_private' => $isPrivate,
                'members_count' => 1,
                'posts_count' => 0,
            ]);

            Follow::query()->create([
                'follower_id' => $owner->actor->id,
                'following_id' => $actor->id,
                'status' => Follow::STATUS_ACCEPTED,
                'requested_at' => now(),
                'accepted_at' => now(),
            ]);

            return $community->load('actor');
        });
    }

    private function assertSlugAvailable(string $slug): void
    {
        if (User::query()->where('username', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('openbook.communities.errors.slug_taken'),
            ]);
        }

        if (Actor::query()->where('preferred_username', $slug)->where('domain', config('openbook.domain'))->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('openbook.communities.errors.slug_taken'),
            ]);
        }
    }
}
