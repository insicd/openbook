@extends('layouts.install')

@section('title', 'Installazione completata - Openbook')

@section('content')
    <div class="ob-card">
        <h1>Installazione completata</h1>
        <p>Openbook e stato installato correttamente su <strong>{{ $domain }}</strong>. L'account amministratore
            <strong>{{ '@'.$adminUsername }}</strong> e stato creato.</p>

        <div class="ob-alert" style="background:#fff6e0;border-color:#f0dfa6;color:#8a6d1c">
            <strong>Importante: annota subito questo token.</strong> Non verra piu mostrato dopo aver lasciato questa pagina.
            Serve soltanto se vuoi attivare l'esecuzione dei processi periodici via richiesta web invece che tramite cron
            reale (vedi la documentazione di installazione).
        </div>

        <p>Token cron:</p>
        <pre style="background:var(--ob-color-bg);padding:1rem;border-radius:var(--ob-radius);overflow-x:auto">{{ $cronToken }}</pre>

        <p>
            Nella prossima fase di sviluppo (federazione) potrai configurare, se disponibile sul tuo hosting, un vero
            cron job sul server con un comando simile al seguente:
        </p>
        <pre style="background:var(--ob-color-bg);padding:1rem;border-radius:var(--ob-radius);overflow-x:auto">* * * * * php {{ base_path('artisan') }} openbook:cron >/dev/null 2>&1</pre>

        @unless($storageLinked)
            <div class="ob-alert" style="background:#fdeaea;border-color:#f0aaaa;color:#7a1f1f">
                <strong>Attenzione:</strong> non e stato possibile creare il collegamento pubblico per gli allegati
                (<code>public/storage</code>). Alcuni hosting condivisi non consentono la funzione <code>symlink()</code>.
                Esegui manualmente <code>php artisan storage:link</code> se hai accesso alla riga di comando, oppure
                configura il server affinche <code>public/storage</code> punti a <code>storage/app/public</code>;
                finche non lo fai, le immagini allegate ai post non saranno visibili.
            </div>
        @endunless

        <a href="{{ route('login') }}" class="ob-btn ob-btn--primary">Vai alla pagina di accesso</a>
    </div>
@endsection
