@extends('layouts.app')

@section('title', __('openbook.admin.appearance.preview_title').' - '.config('app.name'))

@section('content')
    <div class="ob-css-preview-banner" role="status">
        {{ __('openbook.admin.appearance.preview_banner') }}
    </div>

    <div class="ob-card ob-composer">
        <div class="ob-composer__main">
            <div class="ob-composer__aside">
                <div class="ob-avatar ob-composer__avatar" style="width:40px;height:40px" aria-hidden="true">A</div>
            </div>
            <div class="ob-field ob-composer__body-field">
                <label class="sr-only" for="ob-css-preview-composer">{{ __('openbook.composer.body_label') }}</label>
                <textarea id="ob-css-preview-composer" rows="2" disabled placeholder="{{ __('openbook.composer.placeholder') }}"></textarea>
            </div>
        </div>
        <div class="ob-composer__toolbar">
            <button type="button" class="ob-btn ob-btn--primary ob-composer__submit" disabled>{{ __('openbook.composer.submit') }}</button>
        </div>
    </div>

    <article class="ob-card ob-post">
        <div class="ob-post__header">
            <div class="ob-avatar" aria-hidden="true">M</div>
            <div class="ob-post__meta">
                <span class="ob-post__author">{{ __('openbook.admin.appearance.sample_author') }}</span>
                <div class="ob-post__handle">@sample</div>
                <div class="ob-post__time">{{ __('openbook.admin.appearance.sample_time') }}</div>
            </div>
        </div>
        <p class="ob-post__community">
            <x-icon name="people" />
            <span>{{ __('openbook.admin.appearance.sample_community') }}</span>
        </p>
        <h2 class="ob-post__title">{{ __('openbook.admin.appearance.sample_title') }}</h2>
        <div class="ob-post__body">
            <p>{{ __('openbook.admin.appearance.sample_body') }}</p>
        </div>
        <div class="ob-post__actions">
            <button type="button" class="ob-post__action ob-post__action--active" disabled>
                <x-icon name="heart" />
                <span class="ob-post__action-count">12</span>
            </button>
            <span class="ob-post__action">
                <x-icon name="comment" />
                <span class="ob-post__action-count">3</span>
            </span>
            <span class="ob-post__action">
                <x-icon name="share" />
                <span class="ob-post__action-count">1</span>
            </span>
        </div>
    </article>

    <article class="ob-card ob-post">
        <div class="ob-post__header">
            <div class="ob-avatar" aria-hidden="true">L</div>
            <div class="ob-post__meta">
                <span class="ob-post__author">{{ __('openbook.admin.appearance.sample_author_two') }}</span>
                <div class="ob-post__handle">@lucia</div>
                <div class="ob-post__time">{{ __('openbook.admin.appearance.sample_time') }}</div>
            </div>
        </div>
        <div class="ob-post__body">
            <p>{{ __('openbook.admin.appearance.sample_body_two') }}</p>
        </div>
        <div class="ob-post__actions">
            <button type="button" class="ob-post__action" disabled>
                <x-icon name="heart" />
                <span class="ob-post__action-count">4</span>
            </button>
            <span class="ob-post__action">
                <x-icon name="comment" />
                <span class="ob-post__action-count">1</span>
            </span>
        </div>
        <div class="ob-comment">
            <div class="ob-post__header">
                <div class="ob-avatar" style="width:32px;height:32px;font-size:1rem" aria-hidden="true">M</div>
                <div class="ob-post__meta">
                    <span class="ob-post__author">{{ __('openbook.admin.appearance.sample_author') }}</span>
                    <div class="ob-post__time">{{ __('openbook.admin.appearance.sample_time') }}</div>
                </div>
            </div>
            <div class="ob-comment__body">{{ __('openbook.admin.appearance.sample_comment') }}</div>
        </div>
    </article>

    <script>
        (function () {
            var style = document.getElementById('ob-custom-css');

            window.addEventListener('message', function (event) {
                if (event.origin !== window.location.origin) {
                    return;
                }

                if (!event.data || event.data.type !== 'ob-custom-css' || !style) {
                    return;
                }

                style.textContent = typeof event.data.css === 'string' ? event.data.css : '';
            });

            document.addEventListener('click', function (event) {
                var target = event.target.closest('a, button[type="submit"]');

                if (target) {
                    event.preventDefault();
                }
            }, true);

            document.addEventListener('submit', function (event) {
                event.preventDefault();
            }, true);
        })();
    </script>
@endsection
