<?php

namespace App\Http\Requests\Posts;

use App\Domain\Posts\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
{
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
        $maxKb = (int) config('openbook.media.max_size_kb');

        return [
            'title' => ['nullable', 'string', 'max:255'],
            'content_warning' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:'.(int) config('openbook.posts.max_length')],
            'visibility' => ['required', 'in:'.implode(',', [
                Post::VISIBILITY_PUBLIC,
                Post::VISIBILITY_UNLISTED,
                Post::VISIBILITY_FOLLOWERS,
                Post::VISIBILITY_DIRECT,
            ])],
            'language' => ['nullable', 'string', 'max:8'],
            'images' => ['nullable', 'array', 'max:'.$maxAttachments],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,webp,gif', 'max:'.$maxKb],
            'alt_texts' => ['nullable', 'array'],
            'alt_texts.*' => ['nullable', 'string', 'max:1000'],
            'quoted_post_id' => ['nullable', 'uuid', 'exists:posts,id'],
            'community_id' => ['nullable', 'uuid', 'exists:communities,id'],
            'addressed_group_actor_id' => ['nullable', 'uuid', 'exists:actors,id'],
        ];
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
            'images' => 'immagini',
            'images.*' => 'immagine',
            'quoted_post_id' => 'post citato',
        ];
    }
}
