@extends('layouts.install')

@section('title', 'Database - Installazione Openbook')

@section('content')
    <div class="ob-card">
        <h1>Passo 2 di 3 &middot; Connessione al database</h1>
        <p>Inserisci i parametri MySQL/MariaDB forniti dal tuo hosting. Verranno usati per creare le tabelle di Openbook.</p>

        @if ($error)
            <div class="ob-alert ob-alert--error">{{ $error }}</div>
        @endif

        <form method="POST" action="{{ route('install.database.store') }}" novalidate>
            @csrf

            <div class="ob-field">
                <label for="driver">Tipo di database</label>
                <select id="driver" name="driver" style="width:100%;padding:0.6rem;border-radius:var(--ob-radius);border:1px solid var(--ob-color-border)">
                    <option value="mysql" @selected(old('driver', 'mysql') === 'mysql')>MySQL</option>
                    <option value="mariadb" @selected(old('driver') === 'mariadb')>MariaDB</option>
                </select>
            </div>

            <div class="ob-field">
                <label for="host">Host</label>
                <input type="text" id="host" name="host" value="{{ old('host', '127.0.0.1') }}" required>
            </div>

            <div class="ob-field">
                <label for="port">Porta</label>
                <input type="text" id="port" name="port" value="{{ old('port', '3306') }}" required inputmode="numeric">
            </div>

            <div class="ob-field">
                <label for="database">Nome del database</label>
                <input type="text" id="database" name="database" value="{{ old('database') }}" required>
            </div>

            <div class="ob-field">
                <label for="username">Utente del database</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}" required autocomplete="off">
            </div>

            <div class="ob-field">
                <label for="password">Password del database</label>
                <input type="password" id="password" name="password" autocomplete="off">
            </div>

            <button type="submit" class="ob-btn ob-btn--primary ob-btn--block">Testa connessione e continua</button>
        </form>
    </div>
@endsection
