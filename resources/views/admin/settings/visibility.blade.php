<p class="ob-field__help">{{ __('openbook.public_visibility.intro') }}</p>
@foreach (\App\Application\Services\PublicWebVisibility::MODES as $mode)
    <div class="ob-field">
        <label style="display:flex;align-items:center;gap:0.65rem">
            <input type="radio" name="public_web_visibility" value="{{ $mode }}" @checked(old('public_web_visibility', $publicVisibility) === $mode)>
            {{ __('openbook.public_visibility.'.$mode) }}
        </label>
        <p class="ob-field__help" style="margin-left:1.75rem">{{ __('openbook.public_visibility.'.$mode.'_help') }}</p>
    </div>
    @if ($mode === \App\Application\Services\PublicWebVisibility::ALL)
        <div class="ob-field" style="margin-left:1.75rem" data-public-hashtags>
            <label style="display:flex;align-items:center;gap:0.65rem;font-weight:500">
                <input type="hidden" name="public_web_hashtags" value="0">
                <input type="checkbox" name="public_web_hashtags" value="1" @checked((string) old('public_web_hashtags', $publicHashtags ? '1' : '0') === '1')>
                {{ __('openbook.public_visibility.hashtags') }}
            </label>
            <p class="ob-field__help">{{ __('openbook.public_visibility.hashtags_help') }}</p>
            @error('public_web_hashtags')<p class="ob-field__error">{{ $message }}</p>@enderror
        </div>
    @endif
@endforeach
@error('public_web_visibility')<p class="ob-field__error">{{ $message }}</p>@enderror
<hr style="border:0;border-top:1px solid var(--ob-color-border);margin:1.5rem 0">
<div class="ob-field">
    <label style="display:flex;align-items:center;gap:0.65rem;font-weight:500">
        <input type="hidden" name="guest_home_feed" value="0">
        <input type="checkbox" name="guest_home_feed" value="1" @checked((string) old('guest_home_feed', $guestHomeFeed ? '1' : '0') === '1')>
        {{ __('openbook.public_visibility.home_feed') }}
    </label>
    <p class="ob-field__help">{{ __('openbook.public_visibility.home_feed_help') }}</p>
    @error('guest_home_feed')<p class="ob-field__error">{{ $message }}</p>@enderror
</div>
<script>
    (function () {
        var radios = document.querySelectorAll('[name="public_web_visibility"]');
        var hashtags = document.querySelector('[data-public-hashtags]');
        function update() {
            var selected = document.querySelector('[name="public_web_visibility"]:checked');
            hashtags.hidden = !selected || selected.value !== 'all';
        }
        radios.forEach(function (radio) { radio.addEventListener('change', update); });
        update();
    })();
</script>
