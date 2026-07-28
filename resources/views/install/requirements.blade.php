@extends('layouts.install')

@section('title', 'Requisiti - Installazione Openbook')

@section('content')
    <div class="ob-card">
        <h1>Passo 1 di 3 &middot; Requisiti del server</h1>
        <p>Verifica che l'ambiente ospitante soddisfi i requisiti minimi di Openbook prima di procedere.</p>

        <ul style="list-style:none;padding:0">
            @foreach ($checks as $check)
                <li style="display:flex;gap:0.6rem;align-items:flex-start;padding:0.4rem 0;border-bottom:1px solid var(--ob-color-border)">
                    <span aria-hidden="true" style="font-weight:700;color:{{ $check['ok'] ? 'var(--ob-color-success)' : 'var(--ob-color-danger)' }}">
                        {{ $check['ok'] ? '✓' : '✗' }}
                    </span>
                    <span>
                        <strong>{{ $check['label'] }}</strong><br>
                        <span class="ob-field__help">{{ $check['detail'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>

        @if ($canContinue)
            <a href="{{ route('install.database') }}" class="ob-btn ob-btn--primary" style="margin-top:1rem">Continua</a>
        @else
            <div class="ob-alert ob-alert--error" style="margin-top:1rem">
                Alcuni requisiti non sono soddisfatti. Correggi la configurazione del server e ricarica questa pagina.
            </div>
            <a href="{{ url()->current() }}" class="ob-btn ob-btn--ghost">Ricontrolla</a>
        @endif
    </div>
@endsection
