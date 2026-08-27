@php
    $count = (int) $count;
    $isEmpty = $count < 1;
@endphp
<details
    class="ob-reaction-list{{ $isEmpty ? ' ob-reaction-list--empty' : '' }}"
    data-reaction-list
    data-url="{{ $url }}"
    data-label="{{ $labelTemplate }}"
    data-empty="{{ __('openbook.actions.reaction_list_empty') }}"
    data-loading="{{ __('openbook.actions.reaction_list_loading') }}"
    data-error="{{ __('openbook.actions.reaction_list_error') }}"
    data-more="{{ __('openbook.actions.reaction_list_more') }}"
>
    <summary
        class="ob-post__action-count ob-reaction-list__count"
        data-reaction-count
        aria-label="{{ $ariaLabel }}"
        aria-disabled="{{ $isEmpty ? 'true' : 'false' }}"
    >{{ $count }}</summary>
    <div class="ob-reaction-list__panel">
        <p class="ob-reaction-list__status" data-reaction-status>{{ __('openbook.actions.reaction_list_loading') }}</p>
        <ul class="ob-reaction-list__actors" data-reaction-actors hidden></ul>
        <p class="ob-reaction-list__more" data-reaction-more hidden></p>
        <noscript>
            <a href="{{ $url }}" class="ob-reaction-list__fallback">{{ __('openbook.actions.reaction_list_open') }}</a>
        </noscript>
    </div>
</details>
