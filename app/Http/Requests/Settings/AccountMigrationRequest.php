<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class AccountMigrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['destination' => ['required', 'string', 'max:2048']];
        if ($this->routeIs('settings.migration.store')) {
            $rules['password'] = ['required', 'string', 'current_password'];
            $rules['confirm_migration'] = ['accepted'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'destination' => __('openbook.settings.migration_destination'),
            'confirm_migration' => __('openbook.settings.migration_confirm_label'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('settings.edit');
    }
}
