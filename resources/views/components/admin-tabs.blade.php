@props(['tabs', 'label'])
<nav class="ob-admin-tabs" aria-label="{{ $label }}">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}" @class(['ob-profile-tabs__tab', 'is-active' => $tab['active']])
            @if ($tab['active']) aria-current="page" @endif>{{ $tab['label'] }}</a>
    @endforeach
</nav>
