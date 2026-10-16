@if ($post->poll !== null && $post->isPublished())
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
        $local = ! $post->isRemote();
        $showResults = ! $local || $vote !== null || $closed || request()->query('poll_results') === $post->id;
        $resultsUrl = route('posts.show', $post).'?poll_results='.$post->id.'#poll-'.$post->id;
        $choicesUrl = route('posts.show', $post).'#poll-'.$post->id;
        $percentages = $poll->resultPercentages();
        $previousChoices = $choices->filter(fn ($choice) => ! $poll->options->contains(fn ($option) => $option->name === $choice->name));
        $voteFormId = 'poll-vote-'.$poll->id;
        $canPreview = $local && $vote === null && ! $closed && ! $embed;
        $canViewAnswers = $local && ! $embed && auth()->check() && auth()->user()->can('viewPollVoters', $post);
        $pollErrors = isset($errors) ? $errors->getBag('poll-'.$post->id)->all() : [];
    @endphp
    @if ($poll->getRelation('viewerCanSee'))
    <section class="ob-poll" @unless ($embed) id="poll-{{ $post->id }}" @endunless data-poll-id="{{ $poll->id }}" data-poll-results="{{ $showResults ? 1 : 0 }}" aria-label="{{ __('openbook.polls.title') }}">
        <div class="ob-poll__heading">
            <strong>{{ $closed ? __('openbook.polls.closed') : ($poll->ends_at === null ? __('openbook.polls.open') : __('openbook.polls.title')) }}</strong>
            @if (! $closed && $poll->ends_at !== null)
                <em>({{ __('openbook.polls.ends_hint') }} <time datetime="{{ $poll->ends_at->toIso8601String() }}">{{ $poll->ends_at->translatedFormat('j M Y, H:i') }}</time>)</em>
            @endif
        </div>

        @if ($canChoose)
            <form id="{{ $voteFormId }}" method="POST" action="{{ route('posts.poll.vote', $post) }}" data-poll-form>
                @csrf
                <input type="hidden" name="options_version" value="{{ $poll->optionsVersion() }}">
                <fieldset class="ob-poll__fieldset">
                    <legend data-poll-control @if ($showResults && $local) hidden @endif>{{ $poll->multiple ? __('openbook.polls.select_multiple') : __('openbook.polls.select_single') }}</legend>
        @endif

        <ul class="ob-poll__options">
            @foreach ($poll->options as $option)
                @php
                    $selected = $choices->contains(fn ($choice) => $choice->name === $option->name);
                    $percentage = $percentages[$option->id] ?? null;
                @endphp
                <li @class(['ob-poll__option', 'ob-poll__option--selected' => $selected])>
                    @if ($percentage !== null)
                        <span class="ob-poll__bar" data-poll-result @unless ($showResults) hidden @endunless style="width: {{ $percentage }}%" aria-hidden="true"></span>
                    @endif
                    <div class="ob-poll__row">
                        @if ($canChoose)
                            <input id="poll-option-{{ $option->id }}" type="{{ $poll->multiple ? 'checkbox' : 'radio' }}" name="choices[]" value="{{ $option->id }}" @required(! $poll->multiple) data-poll-control @if ($showResults && $local) hidden disabled @endif>
                            <label class="ob-poll__name" for="poll-option-{{ $option->id }}"><bdi>{{ $option->name }}</bdi></label>
                        @else
                            <span class="ob-poll__name"><bdi>{{ $option->name }}</bdi></span>
                        @endif
                        <span class="ob-poll__count" data-poll-result @unless ($showResults) hidden @endunless>
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
            </form>
        @endif

        @if ($previousChoices->isNotEmpty())
            <div class="ob-poll__previous">
                <strong>{{ __('openbook.polls.your_answer') }}</strong>
                <ul>@foreach ($previousChoices as $choice)<li><bdi>{{ $choice->name }}</bdi></li>@endforeach</ul>
                <p>{{ __('openbook.polls.updated_choices') }}</p>
            </div>
        @endif
        @if ($vote === null && ! $closed && ! $embed && ! auth()->check())
            <p><a href="{{ route('login') }}">{{ __('openbook.polls.sign_in') }}</a></p>
        @elseif ($vote === null && ! $local && ! $closed && ! $embed && ! $canChoose)
            <p><a href="{{ $post->uri }}" target="_blank" rel="noopener noreferrer">{{ __('openbook.polls.original') }}</a></p>
        @endif
        <div class="ob-poll__summary" data-poll-result @unless ($showResults) hidden @endunless>
            @if ($vote !== null)
                <p class="ob-poll__confirmed" data-poll-confirmed tabindex="-1">✓
                    @if ($vote->created_at !== null)
                        <time datetime="{{ $vote->created_at->toIso8601String() }}">{{ __('openbook.polls.voted_on', ['date' => $vote->created_at->translatedFormat('j M Y, H:i')]) }}</time>
                    @else
                        {{ __('openbook.polls.voted') }}
                    @endif
                </p>
            @endif
            <p class="ob-poll__total">{{ $poll->voters_count === null ? __('openbook.polls.results_pending') : trans_choice('openbook.polls.voters', $poll->voters_count, ['count' => $poll->voters_count]) }}</p>
        </div>
        @if ($canChoose || $canPreview || $canViewAnswers)
            <div class="ob-poll__actions">
                @if ($canChoose)
                    <button type="submit" form="{{ $voteFormId }}" class="ob-btn ob-btn--primary" data-poll-submit data-poll-vote-controls @if ($showResults && $local) hidden @endif>{{ __('openbook.polls.vote') }}</button>
                @endif
                @if ($canPreview)
                    <a class="ob-btn ob-btn--secondary" href="{{ $showResults ? $choicesUrl : $resultsUrl }}"
                        data-poll-results-toggle data-results-url="{{ $resultsUrl }}" data-choices-url="{{ $choicesUrl }}" aria-expanded="{{ $showResults ? 'true' : 'false' }}">
                        <span data-poll-show-label @if ($showResults) hidden @endif>{{ __('openbook.polls.show_results') }}</span>
                        <span data-poll-choices-label @unless ($showResults) hidden @endunless>{{ __('openbook.polls.back_to_choices') }}</span>
                    </a>
                @endif
                @if ($canViewAnswers)
                    <a class="ob-btn ob-btn--secondary" href="{{ route('posts.poll.voters', $post) }}">{{ __('openbook.polls.view_voters') }}</a>
                @endif
                @if ($canChoose)
                    <details class="ob-poll__info">
                        <summary class="ob-icon-btn" aria-label="{{ __('openbook.polls.voting_info') }}" title="{{ __('openbook.polls.final_choice').($local ? ' '.__('openbook.polls.author_can_see') : '') }}"><x-icon name="info" /></summary>
                        <div class="ob-poll__info-panel" role="note">
                            <p>{{ __('openbook.polls.final_choice') }}</p>
                            @if ($local)<p>{{ __('openbook.polls.author_can_see') }}</p>@endif
                        </div>
                    </details>
                @endif
            </div>
        @endif
        <div data-poll-feedback role="alert" @if ($pollErrors === []) hidden @endif>@foreach ($pollErrors as $error)<p>{{ $error }}</p>@endforeach</div>
        <p class="sr-only" data-poll-status role="status" aria-live="polite"></p>
    </section>
    @endif
@endif
