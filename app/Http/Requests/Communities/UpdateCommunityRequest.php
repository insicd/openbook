<?php

namespace App\Http\Requests\Communities;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommunityRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'summary' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:'.$maxKb],
            'cover' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('openbook.communities.name'),
            'summary' => __('openbook.communities.summary'),
            'avatar' => __('openbook.settings.avatar_label'),
            'cover' => __('openbook.settings.cover_label'),
        ];
    }
}
