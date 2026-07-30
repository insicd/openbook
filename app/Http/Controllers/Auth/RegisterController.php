<?php

namespace App\Http\Controllers\Auth;

use App\Application\Services\AccountRegistrar;
use App\Application\Services\InstanceSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    public function __construct(
        private readonly AccountRegistrar $accountRegistrar,
    ) {}

    public function create(): View|RedirectResponse
    {
        if (! app(InstanceSettings::class)->registrationOpen()) {
            abort(403, __('openbook.auth.registration_closed'));
        }

        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        if (! app(InstanceSettings::class)->registrationOpen()) {
            abort(403, __('openbook.auth.registration_closed'));
        }

        $data = $request->validated();

        $user = $this->accountRegistrar->register([
            'username' => mb_strtolower($data['username']),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()
            ->route('profile.show', $user->username)
            ->with('status', 'Benvenuto su Openbook! Il tuo account e stato creato.');
    }
}
