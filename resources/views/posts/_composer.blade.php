@php
    $maxAttachments = (int) config('openbook.media.max_attachments_per_post');
    $defaultVisibility = auth()->user()->settings?->default_post_visibility ?: 'public';
    $quotedPost = $quotedPost ?? null;
    $composerCommunities = $composerCommunities ?? collect();
    $selectedCommunityId = old('community_id', $selectedCommunityId ?? null);
    $addressedGroupActor = $addressedGroupActor ?? null;

    $titleOpen = old('title') || $errors->has('title');
    $cwOpen = old('content_warning') || $errors->has('content_warning');
    $mediaOpen = old('alt_texts') || $errors->has('images') || $errors->has('images.*') || $errors->has('alt_texts.*');
    $visibilityOpen = old('visibility') || $errors->has('visibility') || $defaultVisibility !== 'public';
    $communityOpen = $composerCommunities->isNotEmpty() && ($selectedCommunityId || $errors->has('community_id'));
@endphp

<div class="ob-card ob-composer{{ $quotedPost ? ' ob-composer--quoting' : '' }}" id="ob-composer">
    <form method="POST" action="{{ route('posts.store') }}" enctype="multipart/form-data">
        @csrf

        @if ($addressedGroupActor)
            <input type="hidden" name="addressed_group_actor_id" value="{{ $addressedGroupActor->id }}">
            <div class="ob-composer__quote-banner">
                <x-icon name="people" />
                <span>{{ __('openbook.composer.addressing_remote', ['handle' => '!'.$addressedGroupActor->handle()]) }}</span>
            </div>
        @endif

        @if ($quotedPost)
            <input type="hidden" name="quoted_post_id" value="{{ $quotedPost->id }}">
            <div class="ob-composer__quote-banner">
                <x-icon name="quote" />
                <span>{{ __('openbook.composer.quoting', ['name' => $quotedPost->actor?->displayName() ?: $quotedPost->actor?->handle()]) }}</span>
                <a href="{{ route('feed.index') }}" class="ob-composer__quote-cancel">{{ __('openbook.composer.quote_cancel') }}</a>
            </div>
        @endif

        <div class="ob-composer__main">
            <x-avatar :user="auth()->user()" style="width:44px;height:44px" />
            <div class="ob-field ob-composer__body-field">
                <label for="composer-body" class="sr-only">{{ __('openbook.composer.body_label') }}</label>
                <textarea id="composer-body" name="body" rows="{{ $quotedPost ? 3 : 1 }}" required maxlength="{{ config('openbook.posts.max_length') }}"
                    placeholder="{{ $quotedPost ? __('openbook.composer.quote_placeholder') : __('openbook.composer.placeholder') }}"
                    data-mention-autocomplete
                    @if ($quotedPost) autofocus @endif>{{ old('body') }}</textarea>
            </div>
        </div>

        @if ($quotedPost)
            <div class="ob-composer__quote">
                @include('posts._card', [
                    'post' => $quotedPost,
                    'embed' => true,
                    'embedDepth' => 1,
                    'linkToPost' => true,
                ])
            </div>
        @endif

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
                    <option value="public" @selected(old('visibility', $defaultVisibility) === 'public')>{{ __('openbook.visibility.public') }}</option>
                    <option value="unlisted" @selected(old('visibility', $defaultVisibility) === 'unlisted')>{{ __('openbook.visibility.unlisted') }}</option>
                    <option value="followers" @selected(old('visibility', $defaultVisibility) === 'followers')>{{ __('openbook.visibility.followers') }}</option>
                    <option value="direct" @selected(old('visibility', $defaultVisibility) === 'direct')>{{ __('openbook.visibility.direct') }}</option>
                </select>
            </div>
        </div>

        @if ($composerCommunities->isNotEmpty())
            <div class="ob-composer__panel" id="composer-panel-community" @unless($communityOpen) hidden @endunless>
                <div class="ob-field">
                    <label for="composer-community">{{ __('openbook.composer.community_label') }}</label>
                    <select id="composer-community" name="community_id">
                        <option value="">{{ __('openbook.composer.community_none') }}</option>
                        @foreach ($composerCommunities as $communityOption)
                            <option value="{{ $communityOption->id }}" @selected((string) $selectedCommunityId === (string) $communityOption->id)>
                                {{ $communityOption->actor?->displayName() ?: $communityOption->slug }}
                            </option>
                        @endforeach
                    </select>
                    <p class="ob-field__help">{{ __('openbook.composer.community_help') }}</p>
                    @error('community_id') <p class="ob-field__error">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

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
                @if ($composerCommunities->isNotEmpty())
                    <button type="button" class="ob-icon-btn ob-composer__toggle {{ $communityOpen ? 'is-active' : '' }}"
                        aria-label="{{ __('openbook.composer.community_label') }}" aria-expanded="{{ $communityOpen ? 'true' : 'false' }}"
                        onclick="obToggleComposerPanel('composer-panel-community', this)">
                        <x-icon name="people" />
                    </button>
                @endif
                <x-emoji-trigger target="composer-body" />
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
