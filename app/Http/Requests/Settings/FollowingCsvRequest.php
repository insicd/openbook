<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class FollowingCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    public function rules(): array
    {
        return ['csv' => ['required', 'file', 'max:1024']];
    }

    protected function getRedirectUrl(): string
    {
        return route('settings.edit', ['tab' => 'import_export']);
    }
}
