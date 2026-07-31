@php
    $activeTab = $activeTab ?? 'posts';
@endphp

<nav class="ob-scope-switch ob-profile-tabs" role="tablist" aria-label="{{ __('openbook.profile.tabs_aria') }}">
    <a href="{{ $postsUrl }}"
       class="ob-btn {{ $activeTab === 'posts' ? 'ob-btn--primary' : 'ob-btn--ghost' }}"
       role="tab"
       aria-selected="{{ $activeTab === 'posts' ? 'true' : 'false' }}">{{ __('openbook.profile.tab_posts') }}</a>
    <a href="{{ $photosUrl }}"
       class="ob-btn {{ $activeTab === 'photos' ? 'ob-btn--primary' : 'ob-btn--ghost' }}"
       role="tab"
       aria-selected="{{ $activeTab === 'photos' ? 'true' : 'false' }}">{{ __('openbook.profile.tab_photos') }}</a>
</nav>
