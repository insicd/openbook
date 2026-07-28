@extends('layouts.app')

@section('title', __('openbook.search.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-narrow">
        <h1>{{ __('openbook.search.title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.search.help') }}</p>

        <form method="POST" action="{{ route('search.perform') }}" novalidate>
            @csrf

            <div class="ob-field">
                <label for="search-q">{{ __('openbook.search.placeholder') }}</label>
                <input type="text" id="search-q" name="q" value="{{ old('q') }}"
                       placeholder="{{ __('openbook.search.placeholder') }}"
                       required autofocus
                       @if ($errors->has('q')) aria-invalid="true" @endif>
                @error('q')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.search.submit') }}</button>
        </form>
    </div>
@endsection
