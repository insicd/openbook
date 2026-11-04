@props(['src' => null, 'name'])

@if ($src)
    <button type="button" class="ob-profile-avatar ob-profile-avatar--zoom"
        data-lightbox-trigger data-full-src="{{ $src }}"
        aria-label="{{ __('openbook.profile.enlarge_avatar') }}">
        <img src="{{ $src }}" alt="{{ $name }}">
    </button>
@else
    <div class="ob-profile-avatar" aria-hidden="true">
        {{ mb_strtoupper(mb_substr($name, 0, 1)) }}
    </div>
@endif
