@php
    /**
     * Composer unificato per post, commenti e risposte.
     *
     * @var string $mode post|comment|reply
     */
    $mode = $mode ?? 'post';
    $isPost = $mode === 'post';
    $isReply = $mode === 'reply';

    $formId = $formId ?? ($isPost ? 'ob-composer' : null);
    $bodyId = $bodyId ?? ($isPost ? 'composer-body' : 'comment-body');
    $prefix = $prefix ?? ($isPost ? 'composer' : str_replace('-', '_', $bodyId));

    $action = $action ?? route('posts.store');
    $method = strtoupper($method ?? 'POST');
    $editingPost = $editingPost ?? null;
    $isEditing = $isPost && $editingPost !== null;
    $submitLabel = $submitLabel ?? ($isEditing
        ? __('openbook.composer.save')
        : ($isPost
            ? __('openbook.composer.submit')
            : ($isReply ? __('openbook.actions.reply') : __('openbook.actions.comment_submit'))));
    $placeholder = $placeholder ?? ($isPost
        ? __('openbook.composer.placeholder')
        : __('openbook.composer.placeholder'));
    $bodyLabel = $bodyLabel ?? ($isPost
        ? __('openbook.composer.body_label')
        : ($isReply ? __('openbook.comments.reply_to', ['name' => $replyToName ?? '']) : __('openbook.comments.new_label')));
    $maxLength = $maxLength ?? ($isPost
        ? (int) config('openbook.posts.max_length')
        : (int) config('openbook.comments.max_length', 2000));
    $rows = $rows ?? ($isPost ? 1 : ($isReply ? 2 : 3));
    $showLabel = $showLabel ?? ! $isPost;
    $formHidden = $formHidden ?? false;
    $parentCommentId = $parentCommentId ?? null;
    $autofocus = $autofocus ?? false;

    $maxAttachments = (int) config('openbook.media.max_attachments_per_post');
    $defaultVisibility = auth()->user()->settings?->default_post_visibility ?: 'public';
    $quotedPost = $quotedPost ?? null;
    $composerCommunities = $composerCommunities ?? collect();
    $selectedCommunityId = old('community_id', $selectedCommunityId ?? null);
    $addressedGroupActor = $addressedGroupActor ?? null;

    $titleValue = old('title', $isEditing ? $editingPost->title : '');
    $cwValue = old('content_warning', $isEditing ? $editingPost->content_warning : '');
    $bodyValue = old('body', $isEditing ? $editingPost->body : '');
    $visibilityValue = old('visibility', $isEditing ? $editingPost->visibility : $defaultVisibility);
    $existingMediaCount = $isEditing ? $editingPost->media->count() : 0;
    $remainingAttachments = max(0, $maxAttachments - $existingMediaCount);

    $titleOpen = $isPost && (filled($titleValue) || $errors->has('title'));
    $cwOpen = $isPost && (filled($cwValue) || $errors->has('content_warning'));
    $mediaOpen = old('alt_texts') || $errors->has('images') || $errors->has('images.*') || $errors->has('alt_texts.*')
        || ($isEditing && $existingMediaCount > 0);
    $visibilityOpen = $isPost && ($errors->has('visibility') || $visibilityValue !== 'public');
    $communityOpen = $isPost && ! $isEditing && $composerCommunities->isNotEmpty() && ($selectedCommunityId || $errors->has('community_id'));

    $titleFilled = $isPost && filled($titleValue);
    $cwFilled = $isPost && filled($cwValue);
    $visibilityFilled = $isPost && $visibilityValue !== 'public';
    $communityFilled = $isPost && ! $isEditing && filled($selectedCommunityId);

    $inModal = (bool) ($inModal ?? false);
    $composerUi = $composerUi ?? null;
    $cardClass = ($inModal ? 'ob-composer ob-composer--in-modal' : 'ob-card ob-composer')
        .($isPost && $quotedPost ? ' ob-composer--quoting' : '')
        .($isReply ? ' ob-composer--reply' : '')
        .($mode === 'comment' ? ' ob-composer--comment' : '');
@endphp

<div
    @if ($formId) id="{{ $formId }}" @endif
    class="{{ $cardClass }}"
    data-composer
    data-composer-mode="{{ $mode }}"
    @if ($formHidden) hidden @endif
>
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data"
        data-composer-create-action="{{ route('posts.store') }}"
        @if ($isEditing) data-composer-editing="1" @endif>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif

        @if (filled($composerUi))
            <input type="hidden" name="composer_ui" value="{{ $composerUi }}">
        @endif

        @if ($parentCommentId)
            <input type="hidden" name="parent_comment_id" value="{{ $parentCommentId }}">
        @endif

        @if ($isPost && $addressedGroupActor)
            <input type="hidden" name="addressed_group_actor_id" value="{{ $addressedGroupActor->id }}">
            <div class="ob-composer__quote-banner">
                <x-icon name="people" />
                <span>{{ __('openbook.composer.addressing_remote', ['handle' => '!'.$addressedGroupActor->handle()]) }}</span>
            </div>
        @endif

        @if ($isPost && $quotedPost && ! $isEditing)
            <input type="hidden" name="quoted_post_id" value="{{ $quotedPost->id }}">
            <div class="ob-composer__quote-banner">
                <x-icon name="quote" />
                <span>{{ __('openbook.composer.quoting', ['name' => $quotedPost->actor?->displayName() ?: $quotedPost->actor?->handle()]) }}</span>
                <a href="{{ route('feed.index') }}" class="ob-composer__quote-cancel">{{ __('openbook.composer.quote_cancel') }}</a>
            </div>
        @endif

        <div class="ob-composer__main">
            <div class="ob-composer__aside">
                <x-avatar :user="auth()->user()" class="ob-composer__avatar" style="width:40px;height:40px" />
                <details class="ob-composer__tip">
                    <summary
                        class="ob-composer__tip-trigger"
                        aria-label="{{ __('openbook.composer.markdown_tip_label') }}"
                        title="{{ __('openbook.composer.markdown_tip_label') }}"
                    >
                        <x-icon name="info" />
                    </summary>
                    <div class="ob-composer__tip-panel" role="note">
                        {{ __('openbook.composer.markdown_help') }}
                    </div>
                </details>
            </div>
            <div class="ob-field ob-composer__body-field">
                <label for="{{ $bodyId }}" @class(['sr-only' => ! $showLabel, 'ob-field__help ob-composer__label' => $showLabel])>
                    {{ $bodyLabel }}
                </label>
                <textarea
                    id="{{ $bodyId }}"
                    name="body"
                    rows="{{ $isPost && $quotedPost && ! $isEditing ? 3 : $rows }}"
                    required
                    maxlength="{{ $maxLength }}"
                    placeholder="{{ $isPost && $quotedPost && ! $isEditing ? __('openbook.composer.quote_placeholder') : $placeholder }}"
                    data-mention-autocomplete
                    data-composer-body
                    @if ($autofocus || ($isPost && $quotedPost) || $isEditing) autofocus @endif
                >{{ $bodyValue }}</textarea>
            </div>
        </div>

        @if ($isPost && $quotedPost)
            <div class="ob-composer__quote">
                @include('posts._card', [
                    'post' => $quotedPost,
                    'embed' => true,
                    'embedDepth' => 1,
                    'linkToPost' => true,
                ])
            </div>
        @endif

        <div class="ob-composer__panels">
            @if ($isPost)
                <div class="ob-composer__panel" id="{{ $prefix }}-panel-title" data-composer-panel @unless($titleOpen) hidden @endunless>
                    <div class="ob-field">
                        <label for="{{ $prefix }}-title">{{ __('openbook.composer.title_label') }}</label>
                        <input type="text" id="{{ $prefix }}-title" name="title" maxlength="255" value="{{ $titleValue }}" data-composer-fill="title">
                    </div>
                </div>

                <div class="ob-composer__panel" id="{{ $prefix }}-panel-cw" data-composer-panel @unless($cwOpen) hidden @endunless>
                    <div class="ob-field">
                        <label for="{{ $prefix }}-cw">{{ __('openbook.composer.cw_label') }}</label>
                        <input type="text" id="{{ $prefix }}-cw" name="content_warning" maxlength="255" value="{{ $cwValue }}"
                            placeholder="{{ __('openbook.composer.cw_placeholder') }}" data-composer-fill="cw">
                    </div>
                </div>
            @endif

            <div class="ob-composer__panel" id="{{ $prefix }}-panel-media" data-composer-panel @unless($mediaOpen) hidden @endunless>
                <div class="ob-field">
                    <label for="{{ $prefix }}-images">{{ __('openbook.composer.images_label') }}</label>
                    <input type="file" id="{{ $prefix }}-images" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif,audio/mpeg,audio/ogg,audio/wav,audio/mp4,audio/x-m4a,audio/flac,audio/webm,audio/aac" multiple data-composer-fill="media">
                    @if ($isEditing && $existingMediaCount > 0)
                        <p class="ob-field__help">{{ __('openbook.composer.existing_media_help', ['count' => $existingMediaCount, 'remaining' => $remainingAttachments]) }}</p>
                    @else
                        <p class="ob-field__help">{{ __('openbook.composer.images_help', ['count' => $maxAttachments]) }}</p>
                    @endif
                </div>
                <div class="ob-field">
                    <label for="{{ $prefix }}-alt">{{ __('openbook.composer.alt_label') }}</label>
                    <input type="text" id="{{ $prefix }}-alt" name="alt_texts[]" maxlength="1000" value="{{ old('alt_texts.0') }}">
                    <p class="ob-field__help">{{ __('openbook.composer.alt_help') }}</p>
                </div>
            </div>

            @if ($isPost)
                <div class="ob-composer__panel" id="{{ $prefix }}-panel-visibility" data-composer-panel @unless($visibilityOpen) hidden @endunless>
                    <div class="ob-field">
                        <label for="{{ $prefix }}-visibility">{{ __('openbook.composer.visibility_label') }}</label>
                        <select id="{{ $prefix }}-visibility" name="visibility" data-composer-fill="visibility" data-composer-default="{{ $isEditing ? $editingPost->visibility : $defaultVisibility }}">
                            <option value="public" @selected($visibilityValue === 'public')>{{ __('openbook.visibility.public') }}</option>
                            <option value="unlisted" @selected($visibilityValue === 'unlisted')>{{ __('openbook.visibility.unlisted') }}</option>
                            <option value="followers" @selected($visibilityValue === 'followers')>{{ __('openbook.visibility.followers') }}</option>
                            <option value="direct" @selected($visibilityValue === 'direct')>{{ __('openbook.visibility.direct') }}</option>
                        </select>
                    </div>
                </div>

                @if (! $isEditing && $composerCommunities->isNotEmpty())
                    <div class="ob-composer__panel" id="{{ $prefix }}-panel-community" data-composer-panel @unless($communityOpen) hidden @endunless>
                        <div class="ob-field">
                            <label for="{{ $prefix }}-community">{{ __('openbook.composer.community_label') }}</label>
                            <select id="{{ $prefix }}-community" name="community_id" data-composer-fill="community">
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
            @endif
        </div>

        <div class="ob-composer__toolbar">
            <div class="ob-composer__toggles">
                @if ($isPost)
                    <button type="button" class="ob-icon-btn ob-composer__toggle {{ $titleOpen ? 'is-active' : '' }} {{ $titleFilled ? 'is-filled' : '' }}"
                        data-composer-toggle="{{ $prefix }}-panel-title"
                        aria-label="{{ __('openbook.composer.title_label') }}"
                        aria-expanded="{{ $titleOpen ? 'true' : 'false' }}"
                        title="{{ __('openbook.composer.title_label') }}">
                        <x-icon name="type" />
                    </button>
                    <button type="button" class="ob-icon-btn ob-composer__toggle {{ $cwOpen ? 'is-active' : '' }} {{ $cwFilled ? 'is-filled' : '' }}"
                        data-composer-toggle="{{ $prefix }}-panel-cw"
                        aria-label="{{ __('openbook.composer.cw_label') }}"
                        aria-expanded="{{ $cwOpen ? 'true' : 'false' }}"
                        title="{{ __('openbook.composer.cw_label') }}">
                        <x-icon name="warning" />
                    </button>
                @endif

                <button type="button" class="ob-icon-btn ob-composer__toggle {{ $mediaOpen ? 'is-active' : '' }}"
                    data-composer-toggle="{{ $prefix }}-panel-media"
                    aria-label="{{ __('openbook.composer.images_label') }}"
                    aria-expanded="{{ $mediaOpen ? 'true' : 'false' }}"
                    title="{{ __('openbook.composer.images_label') }}">
                    <x-icon name="image" />
                </button>

                @if ($isPost)
                    <button type="button" class="ob-icon-btn ob-composer__toggle {{ $visibilityOpen ? 'is-active' : '' }} {{ $visibilityFilled ? 'is-filled' : '' }}"
                        data-composer-toggle="{{ $prefix }}-panel-visibility"
                        aria-label="{{ __('openbook.composer.visibility_label') }}"
                        aria-expanded="{{ $visibilityOpen ? 'true' : 'false' }}"
                        title="{{ __('openbook.composer.visibility_label') }}">
                        <x-icon name="globe" />
                    </button>
                    @if (! $isEditing && $composerCommunities->isNotEmpty())
                        <button type="button" class="ob-icon-btn ob-composer__toggle {{ $communityOpen ? 'is-active' : '' }} {{ $communityFilled ? 'is-filled' : '' }}"
                            data-composer-toggle="{{ $prefix }}-panel-community"
                            aria-label="{{ __('openbook.composer.community_label') }}"
                            aria-expanded="{{ $communityOpen ? 'true' : 'false' }}"
                            title="{{ __('openbook.composer.community_label') }}">
                            <x-icon name="people" />
                        </button>
                    @endif
                @endif

                <x-emoji-trigger :target="$bodyId" />
            </div>

            <button type="submit" class="ob-btn ob-btn--primary ob-composer__submit">{{ $submitLabel }}</button>
        </div>
    </form>
</div>
