<?php

namespace App\Http\Resources\Rest\V1;

use App\Domain\Profiles\ProfileFields;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\RemoteContentSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Actor $actor */
        $actor = $this->resource['actor'];
        $user = $actor->isLocal() ? $actor->user : null;
        $profile = $user?->profile;
        $settings = $user?->settings;
        $suspended = $actor->isSuspended() || $actor->isRemotelySuspended();

        $presentation = ['personName' => $actor->displayName()];
        $summary = $user !== null ? $profile?->bio : $actor->summary;
        if ($summary !== null) {
            $presentation['personSummary'] = $user !== null
                ? $summary
                : RemoteContentSanitizer::toPlainText($summary);
        }
        if ($actor->avatarUrl() !== null) {
            $presentation['personAvatar'] = $actor->avatarUrl();
        }
        if ($actor->coverUrl() !== null) {
            $presentation['personCoverImage'] = $actor->coverUrl();
        }
        $fields = $user !== null ? ($profile?->links ?? []) : ($actor->links ?? []);
        if ($fields !== []) {
            $presentation['personFields'] = array_map(static fn (array $field): array => [
                'label' => $field['label'],
                'value' => ProfileFields::value($field),
            ], $fields);
        }

        $attributes = [
            'personDiscoverable' => (bool) ($settings?->discoverable ?? $actor->discoverable),
            'personIndexable' => (bool) ($settings?->indexable ?? $actor->indexable),
            'personFollowApproval' => $actor->manually_approves_followers,
            'personMemorial' => $actor->memorial,
            'personSuspended' => $suspended,
        ];
        if (! empty($actor->custom_emojis)) {
            $attributes['personCustomEmojis'] = (object) $actor->custom_emojis;
        }
        if (! empty($actor->also_known_as)) {
            $attributes['personAlsoKnownAs'] = $actor->also_known_as;
        }
        $movedTo = $actor->movedTo;
        if ($movedTo !== null && ! $movedTo->isDeleted()
            && in_array($movedTo->type, [Actor::TYPE_PERSON, Actor::TYPE_APPLICATION], true)) {
            $attributes['personMovedTo'] = [
                'personId' => $movedTo->id,
                'personUri' => $movedTo->activityPubId(),
            ];
        }

        $data = [
            'personId' => $actor->id,
            'personUri' => $actor->activityPubId(),
            'personType' => $actor->type,
            'personUsername' => $actor->preferred_username,
            'personDomain' => $actor->domain,
            'personStatus' => $actor->isActive() && ! $suspended ? 'active' : 'inactive',
            'personPublishedAt' => ($user?->created_at ?? $actor->published_at)?->toISOString(),
            'personProfile' => $presentation,
            'personAttributes' => $attributes,
            'personFollowersCount' => $this->resource['followersCount'],
            'personFollowingCount' => $this->resource['followingCount'],
        ];
        if ($user !== null) {
            $data['personRoles'] = array_values(array_filter([
                $user->is_admin ? 'admin' : null,
                $user->is_moderator ? 'moderator' : null,
            ]));
        }

        return $data;
    }
}
