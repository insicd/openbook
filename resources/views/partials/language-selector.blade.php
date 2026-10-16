<details class="ob-language-selector">
    <summary class="ob-icon-btn" aria-label="{{ __('openbook.language.choose') }}" title="{{ __('openbook.language.choose') }}">
        <x-icon name="language" />
        <span class="ob-nav__label">{{ __('openbook.language.choose') }}</span>
    </summary>
    <form method="POST" action="{{ route('locale.update') }}" class="ob-language-selector__menu">
        @csrf
        <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
        @foreach (config('openbook.locales') as $code => $label)
            <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}"
                @if (app()->getLocale() === $code) aria-current="true" @endif>
                <span>{{ $label }}</span>
                @if (app()->getLocale() === $code)<x-icon name="check" />@endif
            </button>
        @endforeach
    </form>
</details>
