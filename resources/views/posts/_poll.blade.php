@if ($post->poll !== null && $post->isRemote() && $post->isPublished())
    @php
        $poll = $post->poll;
        if (! $poll->relationLoaded('viewerVote')) {
            \App\Domain\Polls\Poll::annotateViewerState([$poll], auth()->user()?->actor);
        }
        $vote = $poll->getRelation('viewerVote');
        $choices = $vote?->choices ?? collect();
        $closed = $poll->isClosed();
        $embed = $embed ?? false;
        $canChoose = ! $embed && $vote === null && auth()->check() && auth()->user()->can('vote', $post);
        $percentages = $poll->resultPercentages();
        $previousChoices = $choices->filter(fn ($choice) => ! $poll->options->contains(fn ($option) => $option->name === $choice->name));
        $pollErrors = isset($errors) ? $errors->getBag('poll-'.$post->id)->all() : [];
    @endphp
    @if ($poll->getRelation('viewerCanSee'))
    <section class="ob-poll" @unless ($embed) id="poll-{{ $post->id }}" @endunless data-poll-id="{{ $poll->id }}" tabindex="-1" aria-label="{{ __('openbook.polls.title') }}">
        <div class="ob-poll__heading">
            <strong>{{ $closed ? __('openbook.polls.closed') : __('openbook.polls.open') }}</strong>
            @if (! $closed && $poll->ends_at !== null)
                <span>{{ __('openbook.polls.ends') }} <time datetime="{{ $poll->ends_at->toIso8601String() }}">{{ $poll->ends_at->translatedFormat('j M Y, H:i') }}</time></span>
            @endif
        </div>

        @if ($canChoose)
            <form method="POST" action="{{ route('posts.poll.vote', $post) }}" data-poll-form>
                @csrf
                <input type="hidden" name="options_version" value="{{ $poll->optionsVersion() }}">
                <fieldset class="ob-poll__fieldset">
                    <legend>{{ $poll->multiple ? __('openbook.polls.select_multiple') : __('openbook.polls.select_single') }}</legend>
        @endif

        <ul class="ob-poll__options">
            @foreach ($poll->options as $option)
                @php
                    $selected = $choices->contains(fn ($choice) => $choice->name === $option->name);
                    $percentage = $percentages[$option->id] ?? null;
                @endphp
                <li @class(['ob-poll__option', 'ob-poll__option--selected' => $selected])>
                    @if ($percentage !== null)
                        <span class="ob-poll__bar" style="width: {{ $percentage }}%" aria-hidden="true"></span>
                    @endif
                    <div class="ob-poll__row">
                        @if ($canChoose)
                            <input id="poll-option-{{ $option->id }}" type="{{ $poll->multiple ? 'checkbox' : 'radio' }}" name="choices[]" value="{{ $option->id }}" @required(! $poll->multiple)>
                            <label class="ob-poll__name" for="poll-option-{{ $option->id }}"><bdi>{{ $option->name }}</bdi></label>
                        @else
                            <span class="ob-poll__name"><bdi>{{ $option->name }}</bdi></span>
                        @endif
                        <span class="ob-poll__count">
                            @if ($percentage !== null)<span>{{ $percentage }}%</span> · @endif
                            {{ $option->votes_count === null ? __('openbook.polls.results_pending') : trans_choice('openbook.polls.votes', $option->votes_count, ['count' => $option->votes_count]) }}
                        </span>
                        @if ($selected)
                            <span class="ob-poll__choice">✓ {{ __('openbook.polls.your_answer') }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($canChoose)
                </fieldset>
                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.polls.vote') }}</button>
                <p class="ob-poll__hint">{{ __('openbook.polls.final_choice') }}</p>
            </form>
        @endif

        @if ($previousChoices->isNotEmpty())
            <div class="ob-poll__previous">
                <strong>{{ __('openbook.polls.your_answer') }}</strong>
                <ul>@foreach ($previousChoices as $choice)<li><bdi>{{ $choice->name }}</bdi></li>@endforeach</ul>
                <p>{{ __('openbook.polls.updated_choices') }}</p>
            </div>
        @endif
        @if ($vote !== null)
            <p class="ob-poll__confirmed">✓ {{ __('openbook.polls.confirmed') }}</p>
        @elseif (! $closed && ! $embed && ! auth()->check())
            <p><a href="{{ route('login') }}">{{ __('openbook.polls.sign_in') }}</a></p>
        @elseif (! $closed && ! $embed && ! $canChoose)
            <p><a href="{{ $post->uri }}" target="_blank" rel="noopener noreferrer">{{ __('openbook.polls.original') }}</a></p>
        @endif
        <p class="ob-poll__total">{{ $poll->voters_count === null ? __('openbook.polls.results_pending') : trans_choice('openbook.polls.voters', $poll->voters_count, ['count' => $poll->voters_count]) }}</p>
        <div data-poll-feedback role="alert" @if ($pollErrors === []) hidden @endif>@foreach ($pollErrors as $error)<p>{{ $error }}</p>@endforeach</div>
        <p class="sr-only" data-poll-status role="status" aria-live="polite"></p>
    </section>
    @endif
@endif
