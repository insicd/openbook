@props(['user' => null, 'actor' => null])

{{--
    Accetta indifferentemente un utente locale ("user", il caso storico) o un
    Actor ActivityPub ("actor", locale o remoto): quest'ultimo espone
    displayName()/avatarUrl() gia' uniformati, utile per mostrare autori
    remoti nelle card di post/commenti senza duplicare la logica qui.
--}}
@php
    if ($actor) {
        $displayName = $actor->displayName();
        $avatarUrl = $actor->avatarUrl();
    } else {
        $displayName = $user?->profile?->display_name ?: $user?->username ?: '?';
        $avatarUrl = $user?->profile?->avatarUrl();
    }
@endphp

<div {{ $attributes->class(['ob-avatar']) }} aria-hidden="true">
    @if ($avatarUrl)
        <img src="{{ $avatarUrl }}" alt="">
    @else
        {{ mb_strtoupper(mb_substr($displayName, 0, 1)) }}
    @endif
</div>
