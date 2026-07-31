<?php

namespace App\Http\Requests\Communities;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommunityRequest extends FormRequest
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
        $domain = (string) config('openbook.domain');

        return [
            'slug' => [
                'required',
                'string',
                'min:3',
                'max:32',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('users', 'username'),
                Rule::unique('actors', 'preferred_username')->where(fn ($query) => $query->where('domain', $domain)),
            ],
            'name' => ['required', 'string', 'max:100'],
            'summary' => ['nullable', 'string', 'max:500'],
            'is_private' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge([
                'slug' => mb_strtolower((string) $this->input('slug')),
                'is_private' => $this->boolean('is_private'),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slug' => __('openbook.communities.slug'),
            'name' => __('openbook.communities.name'),
            'summary' => __('openbook.communities.summary'),
        ];
    }
}
