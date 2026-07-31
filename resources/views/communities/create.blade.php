@extends('layouts.app')

@section('title', __('openbook.communities.create').' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-narrow">
        <h1>{{ __('openbook.communities.create') }}</h1>
        <p class="ob-field__help">{{ __('openbook.communities.create_help') }}</p>

        <form method="POST" action="{{ route('communities.store') }}">
            @csrf

            <div class="ob-field">
                <label for="community-name">{{ __('openbook.communities.name') }}</label>
                <input type="text" id="community-name" name="name" value="{{ old('name') }}" required maxlength="100">
                @error('name') <p class="ob-field__error">{{ $message }}</p> @enderror
            </div>

            <div class="ob-field">
                <label for="community-slug">{{ __('openbook.communities.slug') }}</label>
                <input type="text" id="community-slug" name="slug" value="{{ old('slug') }}" required minlength="3" maxlength="32" pattern="[a-z0-9_]+" autocomplete="off">
                <p class="ob-field__help">{{ __('openbook.communities.slug_help', ['domain' => config('openbook.domain')]) }}</p>
                @error('slug') <p class="ob-field__error">{{ $message }}</p> @enderror
            </div>

            <div class="ob-field">
                <label for="community-summary">{{ __('openbook.communities.summary') }}</label>
                <textarea id="community-summary" name="summary" rows="3" maxlength="500">{{ old('summary') }}</textarea>
                @error('summary') <p class="ob-field__error">{{ $message }}</p> @enderror
            </div>

            <div class="ob-field">
                <label>
                    <input type="checkbox" name="is_private" value="1" @checked(old('is_private'))>
                    {{ __('openbook.communities.is_private') }}
                </label>
                <p class="ob-field__help">{{ __('openbook.communities.is_private_help') }}</p>
            </div>

            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.communities.submit_create') }}</button>
        </form>
    </div>
@endsection
