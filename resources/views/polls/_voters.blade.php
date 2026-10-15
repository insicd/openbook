<section class="ob-card">
    <p><a href="{{ route('posts.show', $post) }}#poll-{{ $post->id }}">&larr; {{ __('openbook.polls.back_to_post') }}</a></p>
    <h1>{{ __('openbook.polls.voters_title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.polls.voters_author_only') }}</p>
    <nav class="ob-poll-voters__tabs" aria-label="{{ __('openbook.polls.voters_tabs') }}">
        @foreach ($poll->options as $option)
            <a href="{{ route('posts.poll.voters', [$post, $option->id]) }}"
                @if ($selected->id === $option->id) aria-current="page" @endif>
                <bdi>{{ $option->name }}</bdi>
                <span>{{ trans_choice('openbook.polls.votes', $option->votes_count, ['count' => $option->votes_count]) }}</span>
            </a>
        @endforeach
    </nav>
    <h2><bdi>{{ $selected->name }}</bdi></h2>
    <div data-poll-voters>
        @forelse ($voters as $choice)
            @php($actor = $choice->vote?->actor)
            @continue($actor === null || $actor->status === \App\Federation\Actors\Actor::STATUS_DELETED || ($actor->is_local && $actor->user_id === null))
            <div class="ob-suggestion">
                <a href="{{ $actor->profileUrl() }}" class="ob-mini-profile__link">
                    <x-avatar :actor="$actor" style="width:40px;height:40px" />
                    <div>
                        <div class="ob-post__author">{!! $actor->displayNameHtml() !!}</div>
                        <div class="ob-post__handle">{{ '@'.$actor->handle() }}</div>
                    </div>
                </a>
            </div>
        @empty
            <p class="ob-field__help">{{ __('openbook.polls.voters_empty') }}</p>
        @endforelse
    </div>
    @if ($archivedCount > 0)
        <p class="ob-field__help">{{ trans_choice('openbook.polls.archived_answers', $archivedCount, ['count' => $archivedCount]) }}</p>
    @endif
    @if ($voters->hasPages())
        <nav class="ob-pagination" aria-label="{{ __('openbook.polls.voters_pages') }}">
            @if ($voters->previousPageUrl())
                <a class="ob-btn ob-btn--subtle" href="{{ $voters->previousPageUrl() }}" rel="prev">{{ html_entity_decode(__('pagination.previous'), ENT_QUOTES, 'UTF-8') }}</a>
            @endif
            <span>{{ $voters->currentPage() }} / {{ $voters->lastPage() }}</span>
            @if ($voters->nextPageUrl())
                <a class="ob-btn ob-btn--subtle" href="{{ $voters->nextPageUrl() }}" rel="next">{{ html_entity_decode(__('pagination.next'), ENT_QUOTES, 'UTF-8') }}</a>
            @endif
        </nav>
    @endif
</section>
