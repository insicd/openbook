<?php

namespace App\Http\Requests\Settings;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class DeletePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'endpoint' => [
                'required',
                'string',
                'url',
                'max:4096',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
                        $fail(__('validation.url', ['attribute' => $attribute]));
                    }
                },
            ],
        ];
    }
}
