<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('openbook.locales')))],
            'return_to' => ['sometimes', 'required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                $path = rawurldecode($value);
                if (! str_starts_with($path, '/') || str_starts_with($path, '//')
                    || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
                    $fail(__('openbook.language.invalid_return'));
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['locale' => __('openbook.language.choose')];
    }
}
