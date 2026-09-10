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
        $videoEnabled = (bool) config('openbook.video.enabled', false);
        $maxKb = $videoEnabled
            ? max((int) config('openbook.media.max_size_kb'), (int) config('openbook.video.max_upload_mb') * 1024)
            : (int) config('openbook.media.max_size_kb');
        $allowed = (array) config('openbook.media.allowed_mime_types');

        if ($videoEnabled) {
            $allowed = array_merge($allowed, (array) config('openbook.video.allowed_mime_types'));
        }

        $allowedMimes = implode(',', $allowed);

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
            'images.*' => ['file', 'mimetypes:'.$allowedMimes, 'max:'.$maxKb],
            'alt_texts' => ['nullable', 'array'],
            'alt_texts.*' => ['nullable', 'string', 'max:1000'],
            'quoted_post_id' => ['nullable', 'uuid', 'exists:posts,id'],
            'community_id' => ['nullable', 'uuid', 'exists:communities,id'],
            'addressed_group_actor_id' => ['nullable', 'uuid', 'exists:actors,id'],
            'location_id' => ['nullable', 'required_with:location_label', 'integer', 'exists:geo_cities,geoname_id'],
            'location_label' => ['nullable', 'string', 'max:600'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->file('images', []) as $index => $file) {
                $mime = strtolower((string) $file->getMimeType());
                $isVideo = in_array($mime, (array) config('openbook.video.allowed_mime_types'), true);
                $maxKb = $isVideo
                    ? (int) config('openbook.video.max_upload_mb') * 1024
                    : (int) config('openbook.media.max_size_kb');

                if ($file->getSize() === false || $file->getSize() > $maxKb * 1024) {
                    $validator->errors()->add("images.{$index}", __('validation.max.file', ['attribute' => __('openbook.composer.attachment'), 'max' => $maxKb]));
                }
            }

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
