@extends('layouts.admin')

@section('title', __('openbook.admin.appearance.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.appearance.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.appearance.intro') }}</p>

    <div class="ob-css-workbench">
        <form method="POST" action="{{ route('admin.appearance.update') }}" class="ob-card ob-css-workbench__editor">
            @csrf
            @method('PUT')

            <div class="ob-field">
                <label for="custom_css">{{ __('openbook.admin.appearance.css_label') }}</label>
                <textarea
                    id="custom_css"
                    name="custom_css"
                    class="ob-css-editor"
                    rows="18"
                    maxlength="{{ $maxLength }}"
                    spellcheck="false"
                    autocapitalize="off"
                    autocomplete="off"
                    data-css-preview-source
                    placeholder="{{ __('openbook.admin.appearance.css_placeholder') }}"
                >{{ old('custom_css', $customCss) }}</textarea>
                <p class="ob-field__help">{{ __('openbook.admin.appearance.css_help') }}</p>
                @error('custom_css')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <details class="ob-css-example-wrap">
                <summary>{{ __('openbook.admin.appearance.example_toggle') }}</summary>
                <pre class="ob-css-example" tabindex="0">:root {
  --ob-color-primary: #c45c26;
  --ob-color-primary-dark: #9c4318;
  --ob-color-primary-soft: #f8eadf;
  --ob-color-bg: #f7f1e8;
}
.ob-header {
  background: #2b2118;
}
.ob-brand {
  color: #f4e6d4;
}</pre>
            </details>

            <div class="ob-css-workbench__actions">
                <button type="button" class="ob-btn" data-css-preview-refresh>
                    {{ __('openbook.admin.appearance.preview_button') }}
                </button>
                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.appearance.save') }}</button>
            </div>
        </form>

        <div class="ob-css-workbench__preview">
            <p class="ob-css-workbench__preview-label">{{ __('openbook.admin.appearance.preview_label') }}</p>
            <iframe
                id="ob-css-preview-frame"
                class="ob-css-preview-frame"
                title="{{ __('openbook.admin.appearance.preview_label') }}"
                src="{{ route('admin.appearance.preview') }}"
                sandbox="allow-same-origin allow-scripts"
            ></iframe>
        </div>
    </div>

    <script>
        (function () {
            var source = document.querySelector('[data-css-preview-source]');
            var frame = document.getElementById('ob-css-preview-frame');
            var refresh = document.querySelector('[data-css-preview-refresh]');
            var timer = null;

            if (!source || !frame) {
                return;
            }

            function sendCss() {
                var win = frame.contentWindow;

                if (!win) {
                    return;
                }

                win.postMessage({ type: 'ob-custom-css', css: source.value }, window.location.origin);
            }

            source.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(sendCss, 200);
            });

            frame.addEventListener('load', sendCss);

            if (refresh) {
                refresh.addEventListener('click', sendCss);
            }
        })();
    </script>
@endsection
