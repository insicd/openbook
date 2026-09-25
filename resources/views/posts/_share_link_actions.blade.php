<button
    type="button"
    class="ob-post__menu-item"
    role="menuitem"
    data-copy-url="{{ $postPermalink }}"
    data-copy-label="{{ __('openbook.posts.copy_link') }}"
    data-copy-done="{{ __('openbook.posts.link_copied') }}"
    data-copy-error="{{ __('openbook.posts.copy_link_error') }}"
>
    <x-icon name="link" />
    <span data-copy-text>{{ __('openbook.posts.copy_link') }}</span>
</button>
@if ($post->visibility === \App\Domain\Posts\Post::VISIBILITY_PUBLIC)
    <button
        type="button"
        class="ob-post__menu-item"
        role="menuitem"
        data-native-share-url="{{ $postPermalink }}"
        hidden
    >
        <x-icon name="share" />
        {{ __('openbook.actions.share_link') }}
    </button>
@endif
