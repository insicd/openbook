@extends('layouts.app')

@section('title', ($event->isDeleted() ? __('openbook.events.deleted_title') : $event->name).' - '.config('app.name'))

@if ($event->visibility === \App\Domain\Events\Event::VISIBILITY_PUBLIC && ! $event->isDeleted())
    @php
        $openGraphDescriptionHtml = (string) \App\Domain\Posts\PostBodyRenderer::render(
            collect([$event->distinctSummary(), $event->content])->filter()->implode(' '),
            $event->custom_emojis,
            $event->isRemote() ? parse_url($event->uri, PHP_URL_HOST) : null,
        );
        $openGraphDescription = \Illuminate\Support\Str::limit(
            trim(preg_replace(
                '/\s+/u',
                ' ',
                html_entity_decode(
                    strip_tags(preg_replace('#<(?:br|/p|/li|/h[1-6])\b[^>]*>#i', ' ', $openGraphDescriptionHtml) ?? $openGraphDescriptionHtml),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8',
                ),
            ) ?? ''),
            200,
        );
        $openGraphImage = $event->media->first(fn ($media) => str_starts_with($media->mime_type, 'image/'));
    @endphp

    @push('head')
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('events.show', $event) }}">
        <meta property="og:title" content="{{ $event->name }}">
        @if ($openGraphDescription !== '')
            <meta property="og:description" content="{{ $openGraphDescription }}">
        @endif
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
        @if ($openGraphImage !== null)
            <meta property="og:image" content="{{ url($openGraphImage->url()) }}">
            <meta property="og:image:type" content="{{ $openGraphImage->mime_type }}">
            @if ($openGraphImage->width !== null)
                <meta property="og:image:width" content="{{ $openGraphImage->width }}">
            @endif
            @if ($openGraphImage->height !== null)
                <meta property="og:image:height" content="{{ $openGraphImage->height }}">
            @endif
            <meta property="og:image:alt" content="{{ $openGraphImage->alt_text ?: $event->name }}">
        @endif
    @endpush
@endif

@section('content')
    <div class="ob-card">
        <p><a href="{{ route('events.index') }}">&larr; {{ __('openbook.events.back') }}</a></p>

        @if ($event->isDeleted())
            <div class="ob-empty-state ob-event-tombstone">
                <x-icon name="calendar" />
                <h1>{{ __('openbook.events.deleted_title') }}</h1>
                <p>{{ __('openbook.events.deleted_body') }}</p>
            </div>
        @else
            @php
                $offset = $event->utc_offset_minutes;
                $timezone = $event->timezone
                    ?? ($offset !== null
                        ? sprintf('%s%02d:%02d', $offset >= 0 ? '+' : '-', intdiv(abs($offset), 60), abs($offset) % 60)
                        : config('app.timezone'));
                $timezoneLabel = $event->timezone
                    ?? ($offset !== null ? 'UTC'.$timezone : $timezone);
                $startsAt = $event->start_at->copy()->setTimezone($timezone);
                $endsAt = $event->end_at?->copy()->setTimezone($timezone);
                $location = $event->location;
                $locationAddress = $location?->address ?: collect([
                    $location?->street_address,
                    $location?->locality,
                    $location?->region,
                    $location?->postal_code,
                    $location?->country_name ?: $location?->country_code,
                ])->filter()->unique()->implode(', ');
                $distributors = $event->attributions->where('id', '!=', $event->actor_id);
                $distributorNames = $distributors
                    ->map(fn ($actor) => trim((string) $actor->displayNameHtml()))
                    ->filter();
                $mentionDomain = $event->isRemote() ? parse_url($event->uri, PHP_URL_HOST) : null;
                $distinctSummary = $event->distinctSummary();
            @endphp

            <div class="ob-event-detail__header">
                <div>
                    @if ($event->status === \App\Domain\Events\Event::STATUS_CANCELLED)
                        <span class="ob-badge ob-event-status--cancelled">{{ __('openbook.events.cancelled') }}</span>
                    @elseif ($event->status === \App\Domain\Events\Event::STATUS_TENTATIVE)
                        <span class="ob-badge">{{ __('openbook.events.tentative') }}</span>
                    @elseif ($event->status === \App\Domain\Events\Event::STATUS_POSTPONED)
                        <span class="ob-badge">{{ __('openbook.events.postponed') }}</span>
                    @endif
                    <h1>{!! \App\Domain\Posts\PostBodyRenderer::renderInlineCustomEmojis($event->name, $event->custom_emojis) !!}</h1>
                </div>
            </div>

            <dl class="ob-event-detail__facts">
                <div><dt>{{ __('openbook.events.starts_at') }}</dt><dd>{{ $startsAt->translatedFormat('j F Y, H:i') }}</dd></div>
                @if ($endsAt)
                    <div><dt>{{ __('openbook.events.ends_at') }}</dt><dd>{{ $endsAt->translatedFormat('j F Y, H:i') }}</dd></div>
                @endif
                <div><dt>{{ __('openbook.events.timezone_label') }}</dt><dd>{{ $timezoneLabel }}</dd></div>
                @if ($location)
                    <div>
                        <dt>{{ __('openbook.events.location') }}</dt>
                        <dd>
                            @if ($location->name)
                                <strong>{{ $location->name }}</strong>
                            @endif
                            @if ($locationAddress)
                                @if ($location->name)<br>@endif
                                {{ $locationAddress }}
                            @endif
                        </dd>
                    </div>
                @elseif ($event->is_online)
                    <div><dt>{{ __('openbook.events.location') }}</dt><dd>{{ __('openbook.events.online') }}</dd></div>
                @endif
                @if ($event->category)
                    <div><dt>{{ __('openbook.events.category') }}</dt><dd>{{ $event->category }}</dd></div>
                @endif
                @if ($event->participant_count !== null)
                    <div><dt>{{ __('openbook.events.participants_label') }}</dt><dd>{{ trans_choice('openbook.events.participants_count', $event->participant_count, ['count' => $event->participant_count]) }}</dd></div>
                @endif
                @if ($event->likes_count !== null)
                    <div><dt>{{ __('openbook.events.likes_label') }}</dt><dd>{{ trans_choice('openbook.events.likes_count', $event->likes_count, ['count' => $event->likes_count]) }}</dd></div>
                @endif
            </dl>

            @if ($event->sensitive)
                <details class="ob-post__cw ob-event-detail__sensitive">
                    <summary>{{ __('openbook.events.sensitive_content') }}</summary>
                    @include('events._revealed')
                </details>
            @else
                @include('events._revealed')
            @endif

            <div class="ob-event-detail__actors">
                @if ($event->actor)
                    <div><strong>{{ __('openbook.events.organized_by') }}:</strong> {!! $event->actor->displayNameHtml() !!} <span class="ob-field__help">{{ $event->actor->handle() }}</span></div>
                @endif
                @if ($distributorNames->isNotEmpty())
                    <div><strong>{{ __('openbook.events.distributed_by') }}:</strong> {!! $distributorNames->implode(', ') !!}</div>
                @endif
            </div>

            @if ($event->hashtags->isNotEmpty())
                <p class="ob-event-detail__tags">
                    @foreach ($event->hashtags as $hashtag)
                        <a href="{{ route('hashtags.show', $hashtag->name) }}">#{{ $hashtag->name }}</a>
                    @endforeach
                </p>
            @endif

            <div id="event-actions" class="ob-event-detail__links ob-event-detail__actions">
                @can('update', $event)
                    <a class="ob-btn ob-btn--ghost" href="{{ route('events.edit', $event) }}">{{ __('openbook.events.edit') }}</a>
                    @if ($event->status !== \App\Domain\Events\Event::STATUS_CANCELLED)
                        <form method="POST" action="{{ route('events.cancel', $event) }}" onsubmit="return confirm(@js(__('openbook.events.cancel_confirm')))">
                            @csrf
                            @method('PATCH')
                            <button class="ob-btn ob-btn--ghost" type="submit">{{ __('openbook.events.cancel') }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('events.destroy', $event) }}" onsubmit="return confirm(@js(__('openbook.events.delete_confirm')))">
                        @csrf
                        @method('DELETE')
                        <button class="ob-btn ob-btn--ghost" type="submit">{{ __('openbook.events.delete') }}</button>
                    </form>
                @endcan

                @auth
                    @if ($event->isOpenForInteractions())
                        @if ($viewerLike)
                            <form method="POST" action="{{ route('events.uninterest', $event) }}">
                                @csrf
                                @method('DELETE')
                                <button class="ob-btn ob-btn--ghost" type="submit">{{ __('openbook.events.not_interested') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('events.interest', $event) }}">
                                @csrf
                                <button class="ob-btn ob-btn--ghost" type="submit">{{ __('openbook.events.interested') }}</button>
                            </form>
                        @endif

                        @if ($viewerParticipation && $viewerParticipation->status !== \App\Domain\Events\EventParticipation::STATUS_REJECTED)
                            <form method="POST" action="{{ route('events.leave', $event) }}">
                                @csrf
                                @method('DELETE')
                                <button class="ob-btn ob-btn--ghost" type="submit">{{ __('openbook.events.leave') }}</button>
                            </form>
                            <span class="ob-field__help">
                                {{ __('openbook.events.join_'.$viewerParticipation->status) }}
                            </span>
                        @elseif (in_array($event->join_mode, ['free', 'restricted'], true))
                            @if ($viewerParticipation)
                                <span class="ob-field__help">{{ __('openbook.events.join_rejected') }}</span>
                            @endif
                            <form method="POST" action="{{ route('events.join', $event) }}">
                                @csrf
                                <button class="ob-btn ob-btn--primary" type="submit">{{ __('openbook.events.join') }}</button>
                            </form>
                        @endif
                    @endif
                @endauth

                @if ($event->external_participation_url)
                    <a class="ob-btn ob-btn--primary" href="{{ $event->external_participation_url }}" rel="noopener noreferrer" target="_blank">{{ __('openbook.events.external_link') }}</a>
                @endif
                @foreach ($event->links as $link)
                    <a class="ob-btn ob-btn--ghost" href="{{ $link->url }}" rel="noopener noreferrer" target="_blank">{{ $link->name ?: $link->url }}</a>
                @endforeach
                @if ($event->isRemote() && $event->url)
                    <a class="ob-btn ob-btn--ghost" href="{{ $event->url }}" rel="noopener noreferrer" target="_blank">{{ __('openbook.events.source') }}</a>
                @endif

                @if (auth()->check() || in_array($event->visibility, [\App\Domain\Events\Event::VISIBILITY_PUBLIC, \App\Domain\Events\Event::VISIBILITY_UNLISTED], true))
                    <details class="ob-post__share-menu">
                        <summary class="ob-btn ob-btn--ghost"><x-icon name="share" /> {{ __('openbook.events.share') }}</summary>
                        <div class="ob-post__menu-panel" role="menu">
                            @auth
                                <a href="{{ route('events.share_to_user', $event) }}" class="ob-post__menu-item" role="menuitem">
                                    <x-icon name="message" /> {{ __('openbook.actions.announce_share_user') }}
                                </a>
                            @endauth
                            @if (in_array($event->visibility, [\App\Domain\Events\Event::VISIBILITY_PUBLIC, \App\Domain\Events\Event::VISIBILITY_UNLISTED], true))
                                <button type="button" class="ob-post__menu-item" role="menuitem"
                                    data-native-share-url="{{ route('events.show', $event) }}" hidden>
                                    <x-icon name="share" /> {{ __('openbook.actions.share_link') }}
                                </button>
                                <button type="button" class="ob-post__menu-item" role="menuitem"
                                    data-copy-url="{{ route('events.show', $event) }}"
                                    data-copy-label="{{ __('openbook.posts.copy_link') }}"
                                    data-copy-done="{{ __('openbook.posts.link_copied') }}"
                                    data-copy-error="{{ __('openbook.posts.copy_link_error') }}">
                                    <x-icon name="link" /> <span data-copy-text>{{ __('openbook.posts.copy_link') }}</span>
                                </button>
                            @endif
                        </div>
                    </details>
                @endif
            </div>
        @endif
    </div>

    @if (!$event->isDeleted() && $pendingParticipations->isNotEmpty())
        <div class="ob-card" id="event-participation-requests">
            <h2>{{ __('openbook.events.pending_participations') }}</h2>
            <p class="ob-field__help">{{ __('openbook.events.pending_participations_help') }}</p>
            @foreach ($pendingParticipations as $participation)
                <div class="ob-suggestion">
                    <a href="{{ $participation->actor->profileUrl() }}" class="ob-mini-profile__link">
                        <x-avatar :actor="$participation->actor" style="width:40px;height:40px" />
                        <div>
                            <div class="ob-post__author">{!! $participation->actor->displayNameHtml() !!}</div>
                            <div class="ob-post__handle">{{ '@'.$participation->actor->handle() }}</div>
                        </div>
                    </a>
                    <div class="ob-inline-actions">
                        <form method="POST" action="{{ route('events.participations.accept', [$event, $participation]) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.follow.accept') }}</button>
                        </form>
                        <form method="POST" action="{{ route('events.participations.reject', [$event, $participation]) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.reject') }}</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if (!$event->isDeleted())
        @if ($event->isOpenForInteractions())
            <div id="commenta-evento">
                @auth
                    @include('composer.form', [
                        'mode' => 'comment',
                        'formId' => null,
                        'bodyId' => 'event-comment-body',
                        'prefix' => 'event-comment',
                        'action' => route('event-comments.store', $event),
                        'showLabel' => true,
                        'bodyLabel' => __('openbook.comments.new_label'),
                        'rows' => 3,
                    ])
                @else
                    <div class="ob-card">
                        <p><a href="{{ route('login') }}">{{ __('openbook.comments.login_to_comment') }}</a></p>
                    </div>
                @endauth
            </div>
        @endif

        <div class="ob-card" id="commenti">
            <h2>{{ __('openbook.comments.title', ['count' => $eventCommentsCount]) }}</h2>

            @forelse ($commentTree as $node)
                @include('events._comment', ['node' => $node])
            @empty
                <div class="ob-empty-state">
                    <p>{{ __('openbook.comments.empty') }}</p>
                </div>
            @endforelse
        </div>
    @endif
@endsection
