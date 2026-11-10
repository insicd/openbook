<?php

namespace App\Http\Requests\Posts;

use App\Domain\Polls\LocalPollDefinition;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $options = $this->input('poll.options');
        if ($this->boolean('poll_composer') && is_array($options)
            && collect($options)->every(fn ($value): bool => $value === null || (is_string($value) && trim($value) === ''))) {
            $this->merge(['poll' => null]);
        }
    }

    public function messages(): array
    {
        return LocalPollDefinition::messages();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxAttachments = (int) config('openbook.media.max_attachments_per_post');

        return array_merge(LocalPollDefinition::rules(), [
            'title' => ['nullable', 'string', 'max:255'],
            'content_warning' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:'.(int) config('openbook.posts.max_length')],
            'visibility' => ['required', 'in:'.implode(',', [
                Post::VISIBILITY_PUBLIC,
                Post::VISIBILITY_UNLISTED,
                Post::VISIBILITY_FOLLOWERS,
                Post::VISIBILITY_DIRECT,
            ])],
            'language' => ['nullable', 'string', 'max:'.PostLanguage::MAX_LENGTH],
            'images' => ['nullable', 'array', 'max:'.$maxAttachments],
            'images.*' => AttachmentRules::file(),
            'alt_texts' => ['nullable', 'array'],
            'alt_texts.*' => ['nullable', 'string', 'max:1000'],
            'quoted_post_id' => ['nullable', 'uuid', 'exists:posts,id'],
            'community_id' => ['nullable', 'uuid', 'exists:communities,id'],
            'addressed_group_actor_id' => ['nullable', 'uuid', 'exists:actors,id'],
            'location_id' => ['nullable', 'required_with:location_label', 'integer', 'exists:geo_cities,geoname_id'],
            'location_label' => ['nullable', 'string', 'max:600'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $communityId = $this->input('community_id');
            $addressedGroupId = $this->input('addressed_group_actor_id');

            if (filled($communityId) && filled($addressedGroupId)) {
                $validator->errors()->add(
                    'addressed_group_actor_id',
                    __('openbook.communities.errors.addressed_and_local'),
                );
            }

            $quotedId = $this->input('quoted_post_id');

            if (! is_string($quotedId) || $quotedId === '') {
                return;
            }

            $viewer = $this->user()?->actor;
            $visible = $viewer !== null && Post::query()
                ->whereKey($quotedId)
                ->where('status', Post::STATUS_PUBLISHED)
                ->visibleTo($viewer)
                ->exists();

            if (! $visible) {
                $validator->errors()->add('quoted_post_id', __('openbook.composer.quote_unavailable'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'testo del post',
            'title' => 'titolo',
            'content_warning' => 'avviso sul contenuto',
            'images' => 'allegati',
            'images.*' => 'allegato',
            'quoted_post_id' => 'post citato',
            'location_id' => 'posizione',
        ];
    }
}
