@php
    $activeTab = $activeTab ?? 'posts';
    $showActivityTab = auth()->check() && ($showActivityTab ?? true);
@endphp

<nav class="ob-profile-tabs" role="tablist" aria-label="{{ __('openbook.profile.tabs_aria') }}">
    <a href="{{ $postsUrl }}"
       class="ob-profile-tabs__tab {{ $activeTab === 'posts' ? 'is-active' : '' }}"
       role="tab"
       aria-selected="{{ $activeTab === 'posts' ? 'true' : 'false' }}">{{ __('openbook.profile.tab_posts') }}</a>
    @if ($showActivityTab)
        <a href="{{ $activityUrl }}"
           class="ob-profile-tabs__tab {{ $activeTab === 'activity' ? 'is-active' : '' }}"
           role="tab"
           aria-selected="{{ $activeTab === 'activity' ? 'true' : 'false' }}">{{ __('openbook.profile.tab_activity') }}</a>
    @endif
    <a href="{{ $photosUrl }}"
       class="ob-profile-tabs__tab {{ $activeTab === 'photos' ? 'is-active' : '' }}"
       role="tab"
       aria-selected="{{ $activeTab === 'photos' ? 'true' : 'false' }}">{{ __('openbook.profile.tab_photos') }}</a>
</nav>
