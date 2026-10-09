<?php

namespace App\Http\Requests\Settings;

use App\Domain\Profiles\ProfileFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
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
        $maxKb = (int) config('openbook.media.max_size_kb');

        return [
            'display_name' => ['required', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:500'],
            'links' => ['nullable', 'array', 'max:'.ProfileFields::MAX_FIELDS],
            'links.*' => ['array:label,value,kind'],
            'links.*.label' => ['nullable', 'required_with:links.*.value', 'string', 'max:'.ProfileFields::MAX_LABEL_LENGTH],
            'links.*.value' => ['nullable', 'required_with:links.*.label', 'string', 'max:'.ProfileFields::MAX_VALUE_LENGTH],
            'links.*.kind' => ['nullable', 'in:link,text'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:'.$maxKb],
            'cover' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:'.$maxKb],
        ];
    }

    protected function prepareForValidation(): void
    {
        $fields = $this->input('links');

        if (! is_array($fields)) {
            return;
        }

        $normalized = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                $normalized[] = $field;

                continue;
            }

            if (! array_key_exists('value', $field) && array_key_exists('url', $field)) {
                $field['value'] = $field['url'];
                $field['kind'] = 'link';
            }

            unset($field['url']);

            foreach (['label', 'value'] as $key) {
                if (is_string($field[$key] ?? null)) {
                    $field[$key] = trim($field[$key]);
                }
            }

            if (blank($field['label'] ?? null) && blank($field['value'] ?? null)) {
                continue;
            }

            $normalized[] = $field;
        }

        $this->merge(['links' => $normalized]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('links', []) as $index => $field) {
                if (! is_array($field) || ! is_string($field['value'] ?? null)) {
                    continue;
                }

                $value = $field['value'];

                if (($field['kind'] ?? null) === 'link' && ! ProfileFields::isLink($value)) {
                    $validator->errors()->add('links.'.$index.'.value', __('openbook.settings.profile_link_invalid'));
                }

                if (ProfileFields::isLink($value) && mb_strlen($value) > ProfileFields::MAX_URL_LENGTH) {
                    $validator->errors()->add('links.'.$index.'.value', __('openbook.settings.profile_link_too_long', ['max' => ProfileFields::MAX_URL_LENGTH]));
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'display_name' => 'nome visualizzato',
            'bio' => 'biografia',
            'avatar' => 'immagine del profilo',
            'cover' => 'immagine di copertina',
        ];
    }
}
