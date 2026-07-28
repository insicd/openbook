@extends('layouts.install')

@section('title', 'Istanza e amministratore - Installazione Openbook')

@section('content')
    <div class="ob-card">
        <h1>Passo 3 di 3 &middot; Istanza e account amministratore</h1>
        <p>Il database e stato configurato correttamente. Ora imposta il nome dell'istanza e crea l'account amministratore.</p>

        <form method="POST" action="{{ route('install.instance.store') }}" novalidate>
            @csrf

            <div class="ob-field">
                <label for="site_name">Nome dell'istanza</label>
                <input type="text" id="site_name" name="site_name" value="{{ old('site_name', 'Openbook') }}" required maxlength="100">
            </div>

            <div class="ob-field">
                <label for="domain">Dominio pubblico (senza https://)</label>
                <input type="text" id="domain" name="domain" value="{{ old('domain', $defaultDomain) }}" required
                       placeholder="social.example.org">
                <p class="ob-field__help">Non potra essere cambiato facilmente in seguito: identifica la tua istanza nel Fediverso.</p>
            </div>

            <div class="ob-field">
                <label class="ob-checkbox">
                    <input type="checkbox" name="registration_open" value="1" @checked(old('registration_open', true))>
                    Consenti la registrazione libera di nuovi account
                </label>
            </div>

            <hr style="border:none;border-top:1px solid var(--ob-color-border);margin:1.5rem 0">

            <h2>Account amministratore</h2>

            <div class="ob-field">
                <label for="admin_username">Nome utente</label>
                <input type="text" id="admin_username" name="admin_username" value="{{ old('admin_username') }}"
                       required minlength="3" maxlength="32" pattern="[a-z0-9_]+" autocomplete="username">
            </div>

            <div class="ob-field">
                <label for="admin_email">Email</label>
                <input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required autocomplete="email">
            </div>

            <div class="ob-field">
                <label for="admin_password">Password</label>
                <input type="password" id="admin_password" name="admin_password" required minlength="8" autocomplete="new-password">
            </div>

            <div class="ob-field">
                <label for="admin_password_confirmation">Conferma password</label>
                <input type="password" id="admin_password_confirmation" name="admin_password_confirmation" required minlength="8" autocomplete="new-password">
            </div>

            <button type="submit" class="ob-btn ob-btn--primary ob-btn--block">Completa l'installazione</button>
        </form>
    </div>
@endsection
