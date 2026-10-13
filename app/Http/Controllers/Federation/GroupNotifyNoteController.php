<?php

namespace App\Http\Controllers\Federation;

use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Serialization\GroupNotifyNoteSerializer;
use App\Http\Controllers\Controller;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Http\JsonResponse;

/**
 * Oggetto Note della campanella Group: dereferenziabile se il Group ha
 * Annunciato il post (o il post e' sul wall della community).
 */
final class GroupNotifyNoteController extends Controller
{
    public function __construct(
        private readonly LocalActorResolver $localActors,
    ) {}

    public function show(string $username, string $post): JsonResponse
    {
        $group = $this->localActors->findByUsernameOrFail($username);
        $postModel = Post::query()->whereKey($post)->firstOrFail();

        abort_unless($group->isGroup() && $group->isLocal() && $group->isActive(), 404);
        abort_unless($postModel->isPublished(), 404);
        $postModel->loadMissing('community');

        $onWall = $postModel->community_id !== null
            && $postModel->community?->actor_id === $group->id;
        $announced = Announce::query()
            ->where('actor_id', $group->id)
            ->where('post_id', $postModel->id)
            ->exists();

        abort_unless($onWall || $announced, 404);

        return ActivityPubNegotiation::response(
            GroupNotifyNoteSerializer::note($group, $postModel),
        );
    }
}
