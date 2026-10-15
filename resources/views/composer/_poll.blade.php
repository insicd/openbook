@php
    $definition = \App\Domain\Polls\LocalPollDefinition::class;
    $pollOptions = old('poll.options', ['', '']);
    $pollOptions = is_array($pollOptions) ? array_values(array_slice($pollOptions, 0, $definition::MAX_OPTIONS)) : ['', ''];
    $pollOptions = array_pad($pollOptions, $definition::MIN_OPTIONS, '');
    $hasPoll = collect($pollOptions)->contains(fn ($value) => is_string($value) && trim($value) !== '');
    $pollOpen = $hasPoll || $errors->has('poll') || $errors->has('poll.*') || old('poll_expanded', false);
@endphp
<details class="ob-composer__poll" id="{{ $prefix }}-poll" data-poll-composer
    data-option-label="{{ __('openbook.polls.option') }}"
    data-option-remove="{{ __('openbook.polls.remove_option') }}"
    data-option-length="{{ __('openbook.polls.option_length') }}"
    data-option-limit="{{ $definition::MAX_OPTIONS }}"
    data-option-maxlength="{{ $definition::MAX_OPTION_LENGTH }}" @if ($pollOpen) open @endif>
    <summary>{{ __('openbook.polls.attach') }}</summary>
    <fieldset class="ob-poll__fieldset" data-poll-composer-fields>
    <legend class="sr-only">{{ __('openbook.polls.title') }}</legend>
    <input type="hidden" name="poll_composer" value="1">
    <p class="ob-field__help">{{ __('openbook.polls.composer_help') }}</p>
    <div data-poll-option-list>
        @foreach ($pollOptions as $index => $name)
            <div class="ob-field ob-poll-composer__option" data-poll-option-row>
                <label for="{{ $prefix }}-poll-option-{{ $index }}" data-poll-option-label>{{ __('openbook.polls.option') }} {{ $index + 1 }}</label>
                <div class="ob-poll-composer__input">
                    <input type="text" id="{{ $prefix }}-poll-option-{{ $index }}" name="poll[options][]"
                        value="{{ is_string($name) ? $name : '' }}" maxlength="{{ $definition::MAX_OPTION_LENGTH * 2 }}" data-poll-option-input>
                    <button type="submit" class="ob-btn ob-btn--secondary" name="poll_option_action" value="remove:{{ $index }}"
                        formaction="{{ route('posts.poll.options') }}" formnovalidate data-poll-option-remove @disabled(count($pollOptions) <= $definition::MIN_OPTIONS)
                        aria-label="{{ __('openbook.polls.remove_option') }} {{ $index + 1 }}">−</button>
                </div>
                @error('poll.options.'.$index)<p class="ob-field__error">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>
    <div class="ob-poll-composer__actions">
        <button type="submit" class="ob-btn ob-btn--secondary" name="poll_option_action" value="add"
            formaction="{{ route('posts.poll.options') }}" formnovalidate data-poll-option-add @disabled(count($pollOptions) >= $definition::MAX_OPTIONS)>+ {{ __('openbook.polls.add_option') }}</button>
        <button type="submit" class="ob-btn ob-btn--secondary" name="poll_option_action" value="clear"
            formaction="{{ route('posts.poll.options') }}" formnovalidate data-poll-composer-clear>{{ __('openbook.polls.remove_poll') }}</button>
    </div>
    <div class="ob-field">
        <input type="hidden" name="poll[multiple]" value="0">
        <label class="ob-poll-composer__multiple"><input type="checkbox" name="poll[multiple]" value="1" @checked(old('poll.multiple', false))> {{ __('openbook.polls.allow_multiple') }}</label>
    </div>
    <div class="ob-field">
        <label for="{{ $prefix }}-poll-duration">{{ __('openbook.polls.duration') }}</label>
        <select id="{{ $prefix }}-poll-duration" name="poll[duration_seconds]">
            @foreach ([300 => '5m', 1800 => '30m', 3600 => '1h', 21600 => '6h', 86400 => '1d', 259200 => '3d', 604800 => '7d'] as $seconds => $label)
                <option value="{{ $seconds }}" @selected((int) old('poll.duration_seconds', $definition::DEFAULT_DURATION) === $seconds)>{{ __('openbook.polls.durations.'.$label) }}</option>
            @endforeach
        </select>
    </div>
    @foreach (['poll', 'poll.options', 'poll.multiple', 'poll.duration_seconds'] as $field)
        @error($field)<p class="ob-field__error">{{ $message }}</p>@enderror
    @endforeach
    <p class="ob-field__help">{{ __('openbook.polls.immutable') }}</p>
    <noscript><p class="ob-field__help">{{ __('openbook.polls.noscript_options') }}</p></noscript>
    </fieldset>
</details>
