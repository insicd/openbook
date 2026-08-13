<?php

namespace App\Http\Requests\Comments;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
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
        $allowedMimes = implode(',', (array) config('openbook.media.allowed_mime_types'));

        return [
            'body' => ['required', 'string', 'max:'.(int) config('openbook.comments.max_length', 2000)],
            'parent_comment_id' => ['nullable', 'uuid', 'exists:comments,id'],
            'images' => ['nullable', 'array', 'max:'.$maxAttachments],
            'images.*' => ['file', 'mimetypes:'.$allowedMimes, 'max:'.$maxKb],
            'alt_texts' => ['nullable', 'array'],
            'alt_texts.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'commento',
            'images' => 'allegati',
            'images.*' => 'allegato',
        ];
    }
}
