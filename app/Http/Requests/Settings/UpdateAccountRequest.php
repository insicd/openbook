<?php

namespace App\Http\Requests\Settings;

use App\Domain\Accounts\UserSetting;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
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
        return [
            'locale' => ['required', 'string', 'in:'.implode(',', array_keys((array) config('openbook.locales')))],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all_with_bc'],
            'default_post_visibility' => ['required', 'in:'.implode(',', [
                Post::VISIBILITY_PUBLIC,
                Post::VISIBILITY_UNLISTED,
                Post::VISIBILITY_FOLLOWERS,
                Post::VISIBILITY_DIRECT,
            ])],
            'manually_approves_followers' => ['nullable', 'boolean'],
            'discoverable' => ['nullable', 'boolean'],
            'indexable' => ['nullable', 'boolean'],
            'direct_message_policy' => ['nullable', 'in:'.implode(',', [
                UserSetting::DM_POLICY_EVERYONE,
                UserSetting::DM_POLICY_FOLLOWERS,
                UserSetting::DM_POLICY_NOBODY,
            ])],
        ];
    }

    public function attributes(): array
    {
        return ['timezone' => __('openbook.settings.timezone_label')];
    }

    protected function getRedirectUrl(): string
    {
        return route('settings.edit', ['tab' => 'account']);
    }
}
