@extends('layouts.app')

@section('title', __(isset($event) ? 'openbook.events.composer.edit_title' : 'openbook.events.composer.title').' - '.config('app.name'))

@section('content')
    @php
        $editing = isset($event);
        $eventTimezone = $editing ? ($event->timezone ?: config('app.timezone')) : config('app.timezone');
        $defaultMode = $editing ? ($event->is_online ? ($event->location ? 'hybrid' : 'online') : 'physical') : 'physical';
        $modeValue = old('mode', $defaultMode);
        $joinModeValue = old('join_mode', $editing ? $event->join_mode : 'free');
        $visibilityValue = old('visibility', $editing ? $event->visibility : 'public');
        $timezoneValue = old('timezone', $eventTimezone);
        $locationLabel = $editing ? ($event->location?->city?->label() ?? $event->location?->locality) : null;
    @endphp
    <div class="ob-card ob-event-composer__intro">
        <a href="{{ route('events.index') }}" class="ob-back-link">&larr; {{ __('openbook.events.composer.back') }}</a>
        <div class="ob-section-heading">
            <div>
                <h1>{{ __($editing ? 'openbook.events.composer.edit_title' : 'openbook.events.composer.title') }}</h1>
                <p class="ob-field__help">{{ __($editing ? 'openbook.events.composer.edit_subtitle' : 'openbook.events.composer.subtitle') }}</p>
            </div>
            <span class="ob-badge">{{ __('openbook.events.composer.preview_badge') }}</span>
        </div>
    </div>

    @if ($errors->any())
        <div class="ob-alert ob-alert--error" role="alert">
            <strong>{{ __('openbook.events.composer.error_summary') }}</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="ob-event-composer" method="POST" action="{{ $editing ? route('events.update', $event) : route('events.store') }}" enctype="multipart/form-data">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif
        <section class="ob-card ob-event-composer__section">
            <div class="ob-event-composer__section-heading">
                <x-icon name="edit" />
                <div>
                    <h2>{{ __('openbook.events.composer.details_title') }}</h2>
                    <p>{{ __('openbook.events.composer.details_help') }}</p>
                </div>
            </div>

            <div class="ob-field">
                <label for="event-name">{{ __('openbook.events.composer.name') }}</label>
                <input type="text" id="event-name" name="name" maxlength="500" required value="{{ old('name', $editing ? $event->name : '') }}" placeholder="{{ __('openbook.events.composer.name_placeholder') }}">
            </div>
            <div class="ob-event-composer__grid">
                <div class="ob-field">
                    <label for="event-cover">{{ __('openbook.events.composer.cover') }}</label>
                    <input type="file" id="event-cover" name="cover" accept="image/jpeg,image/png,image/webp,image/gif">
                    <p class="ob-field__help">{{ __($editing && $event->media->isNotEmpty() ? 'openbook.events.composer.cover_replace_help' : 'openbook.events.composer.cover_help') }}</p>
                </div>
                <div class="ob-field">
                    <label for="event-cover-alt">{{ __('openbook.events.composer.cover_alt') }}</label>
                    <input type="text" id="event-cover-alt" name="cover_alt" maxlength="1000" value="{{ old('cover_alt', $editing ? $event->media->first()?->alt_text : '') }}">
                </div>
            </div>
            <div class="ob-field">
                <label for="event-description">{{ __('openbook.events.composer.description') }}</label>
                <textarea id="event-description" name="content" rows="8" required data-mention-autocomplete placeholder="{{ __('openbook.events.composer.description_placeholder') }}">{{ old('content', $editing ? $event->content : '') }}</textarea>
                <p class="ob-field__help">{{ __('openbook.events.composer.description_help') }}</p>
            </div>
            <label class="ob-checkbox">
                <input type="checkbox" name="sensitive" value="1" @checked(old('sensitive', $editing ? $event->sensitive : false))>
                <span>{{ __('openbook.events.composer.sensitive') }}</span>
            </label>
            <p class="ob-field__help">{{ __('openbook.events.composer.sensitive_help') }}</p>
        </section>

        <section class="ob-card ob-event-composer__section">
            <div class="ob-event-composer__section-heading">
                <x-icon name="calendar" />
                <div>
                    <h2>{{ __('openbook.events.composer.when_title') }}</h2>
                    <p>{{ __('openbook.events.composer.when_help') }}</p>
                </div>
            </div>
            <div class="ob-event-composer__grid ob-event-composer__grid--three">
                <div class="ob-field">
                    <label for="event-start">{{ __('openbook.events.composer.start') }}</label>
                    <input type="datetime-local" id="event-start" name="start_at" required value="{{ old('start_at', $editing ? $event->start_at->copy()->setTimezone($eventTimezone)->format('Y-m-d\\TH:i') : '') }}">
                </div>
                <div class="ob-field">
                    <label for="event-end">{{ __('openbook.events.composer.end') }}</label>
                    <input type="datetime-local" id="event-end" name="end_at" value="{{ old('end_at', $editing && $event->end_at ? $event->end_at->copy()->setTimezone($eventTimezone)->format('Y-m-d\\TH:i') : '') }}">
                    <p class="ob-field__help">{{ __('openbook.events.composer.end_help') }}</p>
                </div>
                <div class="ob-field">
                    <label for="event-timezone">{{ __('openbook.events.composer.timezone') }}</label>
                    <select id="event-timezone" name="timezone" required data-has-old-value="{{ old('timezone') || $editing ? '1' : '0' }}">
                        @foreach ($timezones as $region => $identifiers)
                            <optgroup label="{{ str_replace('_', ' ', $region) }}">
                                @foreach ($identifiers as $identifier)
                                    <option value="{{ $identifier }}" @selected($identifier === $timezoneValue)>{{ str_replace(['_', '/'], [' ', ' / '], $identifier) }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="ob-field__help">{{ __('openbook.events.composer.timezone_help') }}</p>
                </div>
            </div>
        </section>

        <section class="ob-card ob-event-composer__section">
            <div class="ob-event-composer__section-heading">
                <x-icon name="map-pin" />
                <div>
                    <h2>{{ __('openbook.events.composer.where_title') }}</h2>
                    <p>{{ __('openbook.events.composer.where_help') }}</p>
                </div>
            </div>

            <div class="ob-event-composer__grid">
                <div class="ob-field">
                    <label for="event-mode">{{ __('openbook.events.composer.mode') }}</label>
                    <select id="event-mode" name="mode">
                        <option value="physical" @selected($modeValue === 'physical')>{{ __('openbook.events.composer.mode_physical') }}</option>
                        <option value="online" @selected($modeValue === 'online')>{{ __('openbook.events.composer.mode_online') }}</option>
                        <option value="hybrid" @selected($modeValue === 'hybrid')>{{ __('openbook.events.composer.mode_hybrid') }}</option>
                    </select>
                </div>
                <div class="ob-field" data-event-participation-url hidden>
                    <label for="event-participation-url">{{ __('openbook.events.composer.participation_url') }}</label>
                    <input type="url" id="event-participation-url" name="participation_url" value="{{ old('participation_url', $editing ? $event->external_participation_url : '') }}" placeholder="https://">
                </div>
            </div>

            <div data-event-physical @if($modeValue === 'online') hidden @endif>
                @if (config('openbook.locations.catalog_ready', false))
                    <div class="ob-location-picker"
                    data-location-picker
                    data-suggest-url="{{ route('locations.suggest') }}"
                    data-nearest-url="{{ route('locations.nearest') }}"
                    data-empty="{{ __('openbook.composer.location_empty') }}"
                    data-error="{{ __('openbook.composer.location_error') }}"
                    data-geolocation-error="{{ __('openbook.composer.location_geolocation_error') }}"
                    data-selection-required="{{ __('openbook.composer.location_selection_required') }}">
                    <div class="ob-field">
                        <label for="event-location-search">{{ __('openbook.events.composer.city') }}</label>
                        <input type="search" id="event-location-search" name="location_label" maxlength="600" value="{{ old('location_label', $locationLabel) }}" autocomplete="off"
                            placeholder="{{ __('openbook.composer.location_placeholder') }}" data-location-search>
                        <input type="hidden" name="location_id" value="{{ old('location_id', $editing ? $event->location?->geo_city_id : '') }}" data-location-id>
                        <div class="ob-location-picker__suggestions" data-location-suggestions hidden></div>
                    </div>
                    <div class="ob-location-picker__actions">
                        <button type="button" class="ob-btn ob-btn--secondary" data-location-current>{{ __('openbook.composer.location_current') }}</button>
                        <button type="button" class="ob-btn ob-btn--secondary" data-location-remove hidden>{{ __('openbook.composer.location_remove') }}</button>
                    </div>
                    <p class="ob-field__help" data-location-status>{{ __('openbook.composer.location_help') }}</p>
                    </div>
                @else
                    <p class="ob-alert">{{ __('openbook.events.composer.city_catalog_unavailable') }}</p>
                @endif

                <div class="ob-event-composer__grid">
                    <div class="ob-field">
                        <label for="event-venue">{{ __('openbook.events.composer.venue') }}</label>
                        <input type="text" id="event-venue" name="venue" maxlength="500" value="{{ old('venue', $editing ? $event->location?->name : '') }}" placeholder="{{ __('openbook.events.composer.venue_placeholder') }}">
                    </div>
                    <div class="ob-field">
                        <label for="event-address">{{ __('openbook.events.composer.address') }}</label>
                        <input type="text" id="event-address" name="address" maxlength="2000" value="{{ old('address', $editing ? ($event->location?->street_address ?: $event->location?->address) : '') }}" placeholder="{{ __('openbook.events.composer.address_placeholder') }}">
                    </div>
                </div>
            </div>
        </section>

        <section class="ob-card ob-event-composer__section">
            <div class="ob-event-composer__section-heading">
                <x-icon name="globe" />
                <div>
                    <h2>{{ __('openbook.events.composer.publish_title') }}</h2>
                    <p>{{ __('openbook.events.composer.publish_help') }}</p>
                </div>
            </div>
            <div class="ob-event-composer__grid">
                <div class="ob-field">
                    <label for="event-join-mode">{{ __('openbook.events.composer.join_mode') }}</label>
                    <select id="event-join-mode" name="join_mode">
                        <option value="free" @selected($joinModeValue === 'free')>{{ __('openbook.events.composer.join_free') }}</option>
                        <option value="restricted" @selected($joinModeValue === 'restricted')>{{ __('openbook.events.composer.join_restricted') }}</option>
                        <option value="external" @selected($joinModeValue === 'external')>{{ __('openbook.events.composer.join_external') }}</option>
                    </select>
                </div>
                <div class="ob-field ob-event-composer__visibility">
                    <input type="hidden" id="event-visibility" name="visibility" value="{{ $visibilityValue }}">
                    <label class="ob-checkbox" for="event-listed">
                        <input type="checkbox" id="event-listed" @checked($visibilityValue === 'public')>
                        <span>{{ __('openbook.events.composer.listed') }}</span>
                    </label>
                    <p class="ob-field__help">{{ __('openbook.events.composer.listed_help') }}</p>
                </div>
            </div>
        </section>

        <div class="ob-card ob-event-composer__actions">
            <p class="ob-field__help">{{ __('openbook.events.composer.submit_help') }}</p>
            <button type="submit" class="ob-btn ob-btn--primary">{{ __($editing ? 'openbook.events.composer.update_submit' : 'openbook.events.composer.submit') }}</button>
        </div>
    </form>

    <script>
        (function () {
            var timezone = document.getElementById('event-timezone');
            var mode = document.getElementById('event-mode');
            var physical = document.querySelector('[data-event-physical]');
            var participationUrl = document.querySelector('[data-event-participation-url]');
            var listed = document.getElementById('event-listed');
            var visibility = document.getElementById('event-visibility');
            var joinMode = document.getElementById('event-join-mode');

            try {
                var detectedTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
                var knownTimezone = Array.prototype.some.call(timezone.options, function (option) {
                    return option.value === detectedTimezone;
                });
                if (knownTimezone && timezone.dataset.hasOldValue !== '1') {
                    timezone.value = detectedTimezone;
                }
            } catch (error) {}

            function updateMode() {
                physical.hidden = mode.value === 'online';
                participationUrl.hidden = mode.value === 'physical' && joinMode.value !== 'external';
            }

            mode.addEventListener('change', updateMode);
            joinMode.addEventListener('change', updateMode);
            listed.addEventListener('change', function () {
                visibility.value = listed.checked ? 'public' : 'unlisted';
            });
            updateMode();
        })();
    </script>
@endsection
