<?php

namespace App\Http\Requests\Posts;

use App\Domain\Posts\Post;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $post = $this->route('post');

        return $post instanceof Post && $this->user()?->can('update', $post) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxAttachments = (int) config('openbook.media.max_attachments_per_post');
        $maxKb = (int) config('openbook.media.max_size_kb');
        $allowedMimes = implode(',', (array) config('openbook.media.allowed_mime_types'));

        $post = $this->route('post');
        $existing = $post instanceof Post ? $post->media()->count() : 0;
        $maxNew = max(0, $maxAttachments - $existing);

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
            'images' => ['nullable', 'array', 'max:'.$maxNew],
            'images.*' => ['file', 'mimetypes:'.$allowedMimes, 'max:'.$maxKb],
            'alt_texts' => ['nullable', 'array'],
            'alt_texts.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Dopo un invio dal modal, gli errori devono atterrare sulla pagina
     * di modifica (stesso composer), non sul composer "nuovo post" della
     * pagina di partenza.
     */
    protected function failedValidation(Validator $validator): void
    {
        $post = $this->route('post');

        if ($post instanceof Post) {
            throw (new ValidationException($validator))
                ->errorBag($this->errorBag)
                ->redirectTo(route('posts.edit', $post));
        }

        parent::failedValidation($validator);
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
        ];
    }
}
