<?php

namespace App\Http\Requests\Settings;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $httpsEndpoint = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
                $fail(__('validation.url', ['attribute' => $attribute]));
            }
        };

        $base64Url = static function (int $expectedBytes): array {
            return [
                'required',
                'string',
                'max:512',
                'regex:/^[A-Za-z0-9_-]+={0,2}$/',
                static function (string $attribute, mixed $value, Closure $fail) use ($expectedBytes): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $normalized = strtr($value, '-_', '+/');
                    $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
                    $decoded = base64_decode($normalized, true);

                    if ($decoded === false || strlen($decoded) !== $expectedBytes) {
                        $fail(__('validation.regex', ['attribute' => $attribute]));
                    }
                },
            ];
        };

        return [
            'endpoint' => ['required', 'string', 'url', 'max:4096', $httpsEndpoint],
            'expirationTime' => ['nullable', 'integer', 'min:0'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => $base64Url(65),
            'keys.auth' => $base64Url(16),
        ];
    }
}
