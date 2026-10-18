<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class AccountAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['alias' => ['required', 'string', 'max:2048']];
    }

    public function attributes(): array
    {
        return ['alias' => __('openbook.settings.alias_label')];
    }
}
