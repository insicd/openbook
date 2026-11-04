<?php

namespace App\Http\Requests\Admin;

use App\Application\Services\ObjectStorageSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ObjectStorageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAdminister() ?? false;
    }

    public function rules(): array
    {
        $enabled = $this->boolean('enabled');
        $provider = $this->input('provider');

        return [
            'enabled' => ['sometimes', 'boolean'],
            'direct' => ['sometimes', 'boolean'],
            'provider' => ['required', Rule::in(ObjectStorageSettings::PROVIDERS)],
            'bucket' => [Rule::requiredIf($enabled), 'nullable', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/'],
            'access_key' => [Rule::requiredIf($enabled), 'nullable', 'string', 'max:256'],
            'password' => [Rule::requiredIf($enabled && ! app(ObjectStorageSettings::class)->hasSecret()), 'nullable', 'string', 'max:2048'],
            'account_id' => [Rule::requiredIf($enabled && $provider === 'cloudflareR2'), 'nullable', 'string', 'regex:/^[a-f0-9]{32}$/i'],
            'region' => [Rule::requiredIf($enabled && $provider !== 'cloudflareR2'), 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'endpoint' => [Rule::requiredIf($enabled && in_array($provider, ['ovhcloud', 'other'], true)), 'nullable', 'url:https', 'max:2048', 'regex:~^https://[^/?#@]+/?$~i'],
            'path_style' => ['sometimes', 'boolean'],
            'public_url' => [Rule::requiredIf($this->boolean('direct')), 'nullable', 'url:https', 'max:2048', 'regex:~^https://[^/?#@]+(?:/[^?#]*)?$~i'],
        ];
    }

    public function attributes(): array
    {
        return collect(ObjectStorageSettings::FIELDS)
            ->push('password')
            ->mapWithKeys(fn (string $field): array => [$field => __('object_storage.'.$field)])
            ->all();
    }
}
