@props(['target'])

<button
    type="button"
    class="ob-icon-btn ob-emoji-trigger"
    data-emoji-target="{{ $target }}"
    aria-label="{{ __('openbook.emoji.open') }}"
    aria-expanded="false"
    aria-haspopup="dialog"
    title="{{ __('openbook.emoji.open') }}"
>
    <x-icon name="smile" />
</button>
