{{--
  Icona search della navbar: non porta alla pagina /cerca, ma apre un
  campo di input inline. L'invio del form continua a usare la stessa
  route di ricerca (locale o federata).
--}}
<div class="ob-header-search" data-header-panel="search">
    <button
        type="button"
        class="ob-icon-btn"
        id="ob-search-toggle"
        aria-label="{{ __('openbook.nav.search') }}"
        aria-controls="ob-header-search-form"
        aria-expanded="false"
        data-header-panel-toggle
    >
        <x-icon name="search" />
        <span class="ob-nav__label">{{ __('openbook.nav.search') }}</span>
    </button>

    <form
        method="GET"
        action="{{ route('search.create') }}"
        class="ob-header-search__form"
        id="ob-header-search-form"
        hidden
        data-header-panel-content
        novalidate
    >
        <label class="sr-only" for="ob-header-search-q">{{ __('openbook.search.placeholder') }}</label>
        <input
            type="search"
            id="ob-header-search-q"
            name="q"
            value="{{ request('q', old('q')) }}"
            placeholder="{{ __('openbook.search.placeholder') }}"
            minlength="{{ (int) config('openbook.search.min_length', 2) }}"
            required
            autocomplete="off"
            data-header-search-input
            data-search-suggest
        >
        <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.search.submit') }}</button>
    </form>
</div>
