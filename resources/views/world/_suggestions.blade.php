@if ($suggestedActors->isNotEmpty())
    <div data-world-suggestions-result>
        @foreach ($suggestedActors as $rowActor)
            @include('world._suggestion', ['rowActor' => $rowActor])
        @endforeach
        @if ($suggestedActorsHasMore)
            <a href="{{ route('world.discover') }}" class="ob-side-widget__more">{{ __('openbook.world.suggested_more') }}</a>
        @endif
    </div>
@endif
