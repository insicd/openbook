<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect()->route('feed.index');
        }

        return view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->fulfill();
        }

        return redirect()->route('feed.index')->with('status', 'Indirizzo email verificato.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $key = 'verification-resend:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors(['email' => 'Hai gia richiesto troppe email di verifica. Riprova piu tardi.']);
        }

        RateLimiter::hit($key, 300);

        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('feed.index');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'Ti abbiamo inviato una nuova email di verifica.');
    }
}
