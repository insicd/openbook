<?php

namespace App\Http\Requests\Install;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class InstanceSetupRequest extends FormRequest
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
            'site_name' => ['required', 'string', 'max:100'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+(:\d+)?$/i'],
            'registration_open' => ['sometimes', 'boolean'],
            'admin_username' => [
                'required',
                'string',
                'min:3',
                'max:32',
                'regex:/^[a-z0-9_]+$/',
                'unique:users,username',
            ],
            'admin_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'site_name' => 'nome dell\'istanza',
            'domain' => 'dominio',
            'admin_username' => 'nome utente amministratore',
            'admin_email' => 'email amministratore',
            'admin_password' => 'password amministratore',
        ];
    }
}
