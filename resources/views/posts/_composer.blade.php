@php
    $maxAttachments = (int) config('openbook.media.max_attachments_per_post');

    $titleOpen = old('title') || $errors->has('title');
    $cwOpen = old('content_warning') || $errors->has('content_warning');
    $mediaOpen = old('alt_texts') || $errors->has('images') || $errors->has('images.*') || $errors->has('alt_texts.*');
    $visibilityOpen = old('visibility') || $errors->has('visibility');
@endphp

<div class="ob-card ob-composer">
    <form method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="ob-composer__main">
            <x-avatar :user="auth()->user()" style="width:44px;height:44px" />
            <div class="ob-field ob-composer__body-field">
                <label for="composer-body" class="sr-only">{{ __('openbook.composer.body_label') }}</label>
                <textarea id="composer-body" name="body" rows="1" required maxlength="{{ config('openbook.posts.max_length') }}"
                    placeholder="{{ __('openbook.composer.placeholder') }}">{{ old('body') }}</textarea>
            </div>
        </div>

        <div class="ob-composer__panel" id="composer-panel-title" @unless($titleOpen) hidden @endunless>
            <div class="ob-field">
                <label for="composer-title">{{ __('openbook.composer.title_label') }}</label>
                <input type="text" id="composer-title" name="title" maxlength="255" value="{{ old('title') }}">
            </div>
        </div>

        <div class="ob-composer__panel" id="composer-panel-cw" @unless($cwOpen) hidden @endunless>
            <div class="ob-field">
                <label for="composer-cw">{{ __('openbook.composer.cw_label') }}</label>
                <input type="text" id="composer-cw" name="content_warning" maxlength="255" value="{{ old('content_warning') }}"
                    placeholder="{{ __('openbook.composer.cw_placeholder') }}">
            </div>
        </div>

        <div class="ob-composer__panel" id="composer-panel-media" @unless($mediaOpen) hidden @endunless>
            <div class="ob-field">
                <label for="composer-images">{{ __('openbook.composer.images_label') }}</label>
                <input type="file" id="composer-images" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                <p class="ob-field__help">{{ __('openbook.composer.images_help', ['count' => $maxAttachments]) }}</p>
            </div>
            <div class="ob-field">
                <label for="composer-alt">{{ __('openbook.composer.alt_label') }}</label>
                <input type="text" id="composer-alt" name="alt_texts[]" maxlength="1000" value="{{ old('alt_texts.0') }}">
                <p class="ob-field__help">{{ __('openbook.composer.alt_help') }}</p>
            </div>
        </div>

        <div class="ob-composer__panel" id="composer-panel-visibility" @unless($visibilityOpen) hidden @endunless>
            <div class="ob-field">
                <label for="composer-visibility">{{ __('openbook.composer.visibility_label') }}</label>
                <select id="composer-visibility" name="visibility">
                    <option value="public" @selected(old('visibility', 'public') === 'public')>{{ __('openbook.visibility.public') }}</option>
                    <option value="unlisted" @selected(old('visibility') === 'unlisted')>{{ __('openbook.visibility.unlisted') }}</option>
                    <option value="followers" @selected(old('visibility') === 'followers')>{{ __('openbook.visibility.followers') }}</option>
                    <option value="direct" @selected(old('visibility') === 'direct')>{{ __('openbook.visibility.direct') }}</option>
                </select>
            </div>
        </div>

        <div class="ob-composer__toolbar">
            <div class="ob-composer__toggles">
                <button type="button" class="ob-icon-btn ob-composer__toggle {{ $titleOpen ? 'is-active' : '' }}"
                    aria-label="{{ __('openbook.composer.title_label') }}" aria-expanded="{{ $titleOpen ? 'true' : 'false' }}"
                    onclick="obToggleComposerPanel('composer-panel-title', this)">
                    <x-icon name="type" />
                </button>
                <button type="button" class="ob-icon-btn ob-composer__toggle {{ $cwOpen ? 'is-active' : '' }}"
                    aria-label="{{ __('openbook.composer.cw_label') }}" aria-expanded="{{ $cwOpen ? 'true' : 'false' }}"
                    onclick="obToggleComposerPanel('composer-panel-cw', this)">
                    <x-icon name="warning" />
                </button>
                <button type="button" class="ob-icon-btn ob-composer__toggle {{ $mediaOpen ? 'is-active' : '' }}"
                    aria-label="{{ __('openbook.composer.images_label') }}" aria-expanded="{{ $mediaOpen ? 'true' : 'false' }}"
                    onclick="obToggleComposerPanel('composer-panel-media', this)">
                    <x-icon name="image" />
                </button>
                <button type="button" class="ob-icon-btn ob-composer__toggle {{ $visibilityOpen ? 'is-active' : '' }}"
                    aria-label="{{ __('openbook.composer.visibility_label') }}" aria-expanded="{{ $visibilityOpen ? 'true' : 'false' }}"
                    onclick="obToggleComposerPanel('composer-panel-visibility', this)">
                    <x-icon name="globe" />
                </button>
            </div>

            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.composer.submit') }}</button>
        </div>
    </form>
</div>

<script>
    function obToggleComposerPanel(panelId, button) {
        var panel = document.getElementById(panelId);
        if (!panel) {
            return;
        }

        panel.hidden = !panel.hidden;
        button.classList.toggle('is-active', !panel.hidden);
        button.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');

        if (!panel.hidden) {
            var focusable = panel.querySelector('input, textarea, select');
            if (focusable) {
                focusable.focus();
            }
        }
    }
</script>
