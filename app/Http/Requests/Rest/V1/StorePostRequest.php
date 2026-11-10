<?php

namespace App\Http\Requests\Rest\V1;

use App\Domain\Posts\Post;
use App\Http\Controllers\Concerns\ValidatesRestInput;
use App\Http\Requests\Posts\AttachmentRules;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StorePostRequest extends FormRequest
{
    use ValidatesRestInput;

    private array $postData = [];

    private \stdClass $payload;

    private bool $multipart = false;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->multipart = strtolower(trim((string) strtok($this->header('Content-Type', ''), ';'))) === 'multipart/form-data';
        abort_unless($this->isJson() || $this->multipart, 415);
        $json = $this->multipart ? $this->input('post') : $this->getContent();
        if (! is_string($json)) {
            $this->invalid(['post' => ['The post part must contain a JSON object.']]);
        }
        try {
            $payload = json_decode($json, flags: JSON_THROW_ON_ERROR);
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400);
        }
        if (! $payload instanceof \stdClass) {
            $this->invalid(['body' => ['The post must be a JSON object.']]);
        }
        $this->payload = $payload;
        $this->postData = $this->multipart ? $data : $this->json()->all();
    }

    public function validationData(): array
    {
        return $this->postData;
    }

    public function rules(): array
    {
        $withAttachments = $this->multipart && is_array($this->payload->postAttachments ?? null)
            && count($this->payload->postAttachments) > 0;
        $rules = [
            'postContent' => [$withAttachments ? 'present' : 'required', 'string',
                'regex:/\S/u',
                'max:'.(int) config('openbook.posts.max_length')],
            'postVisibility' => ['required', 'string', Rule::in([Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED, Post::VISIBILITY_FOLLOWERS])],
        ];
        if ($this->multipart) {
            $rules += [
                'postAttachments' => ['sometimes', 'array', 'list', 'max:'.(int) config('openbook.media.max_attachments_per_post')],
                'postAttachments.*' => ['required', 'array:filePart,mediaAltText'],
                'postAttachments.*.filePart' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9]{0,63}$/D', 'not_in:post', 'distinct:strict'],
                'postAttachments.*.mediaAltText' => ['nullable', 'string', 'max:1000'],
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $content = $this->postData['postContent'] ?? null;
            if (is_string($content) && $content !== '' && ! preg_match('/\S/u', $content)) {
                $validator->errors()->add('postContent', 'The text must not consist only of whitespace.');
            }
            $allowed = $this->multipart ? ['postContent', 'postVisibility', 'postAttachments'] : ['postContent', 'postVisibility'];
            foreach (array_diff(array_keys($this->postData), $allowed) as $key) {
                $validator->errors()->add((string) $key, 'This field is not supported.');
            }
            if (! $this->multipart) {
                return;
            }
            foreach (array_diff(array_keys($this->request->all()), ['post']) as $key) {
                $validator->errors()->add((string) $key, 'Unexpected form field.');
            }
            $attachments = $this->payload->postAttachments ?? [];
            if (! is_array($attachments)) {
                $validator->errors()->add('postAttachments', 'Attachments must be a JSON array.');

                return;
            }
            $references = [];
            foreach ($attachments as $index => $attachment) {
                $field = 'postAttachments.'.$index;
                if (! $attachment instanceof \stdClass) {
                    $validator->errors()->add($field, 'Each attachment must be a JSON object.');

                    continue;
                }
                $name = $attachment->filePart ?? null;
                if (! is_string($name) || ! preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/D', $name) || $name === 'post') {
                    continue;
                }
                $references[] = $name;
                $file = $this->allFiles()[$name] ?? null;
                // Validate under the public field path, without accepting file data in JSON.
                $fileValidator = validator(['file' => $file], ['file' => ['required', ...AttachmentRules::file()]]);
                foreach ($fileValidator->errors()->all() as $error) {
                    $validator->errors()->add($field.'.filePart', $error);
                }
            }
            foreach (array_diff(array_keys($this->allFiles()), $references) as $key) {
                $validator->errors()->add((string) $key, 'The file is not referenced by postAttachments.');
            }
        });
    }

    /** Map only validated public inputs to the existing publication pipeline. */
    public function publicationData(): array
    {
        $values = $this->validated();
        $files = [];
        $altTexts = [];
        foreach ($values['postAttachments'] ?? [] as $attachment) {
            $files[] = $this->allFiles()[$attachment['filePart']];
            $altTexts[] = ($attachment['mediaAltText'] ?? '') === '' ? null : $attachment['mediaAltText'];
        }

        return ['body' => $values['postContent'], 'visibility' => $values['postVisibility'],
            'images' => $files, 'alt_texts' => $altTexts];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        $this->invalid($validator->errors()->toArray());
    }
}
