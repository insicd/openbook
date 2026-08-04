@extends('layouts.app')

@section('title', __('openbook.communities.edit_title').' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-narrow">
        <p class="ob-field__help" style="margin-bottom:0.5rem">
            <a href="{{ route('communities.show', $community) }}">&larr; {{ __('openbook.communities.back_to_community') }}</a>
        </p>
        <h1>{{ __('openbook.communities.edit_title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.communities.edit_help') }}</p>

        <form method="POST" action="{{ route('communities.update', $community) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="ob-field">
                <label>{{ __('openbook.settings.avatar_label') }}</label>
                <div class="ob-settings-avatar-picker">
                    <x-avatar id="community-avatar-preview" :actor="$community->actor" style="width:64px;height:64px;font-size:1.5rem" />
                    <input type="file" name="avatar" id="community-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif">
                </div>
                <p class="ob-field__help">{{ __('openbook.settings.image_preview_help') }}</p>
                @error('avatar')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label>{{ __('openbook.settings.cover_label') }}</label>
                <div id="community-cover-preview">
                    @if ($community->actor->coverUrl())
                        <img src="{{ $community->actor->coverUrl() }}" alt="" class="ob-settings-cover-preview">
                    @endif
                </div>
                <input type="file" name="cover" id="community-cover-input" accept="image/jpeg,image/png,image/webp,image/gif">
                @error('cover')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label for="community-name">{{ __('openbook.communities.name') }}</label>
                <input type="text" id="community-name" name="name" value="{{ old('name', $community->actor->name) }}" required maxlength="100">
                @error('name') <p class="ob-field__error">{{ $message }}</p> @enderror
            </div>

            <div class="ob-field">
                <label for="community-summary">{{ __('openbook.communities.summary') }}</label>
                <textarea id="community-summary" name="summary" rows="3" maxlength="500">{{ old('summary', $community->actor->summary) }}</textarea>
                @error('summary') <p class="ob-field__error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.settings.save') }}</button>
        </form>
    </div>

    <script>
        (function () {
            function previewImage(inputId, containerId, imgClass) {
                var input = document.getElementById(inputId);
                var container = document.getElementById(containerId);

                if (!input || !container || !window.FileReader) {
                    return;
                }

                input.addEventListener('change', function () {
                    var file = input.files && input.files[0];

                    if (!file || file.type.indexOf('image/') !== 0) {
                        return;
                    }

                    var reader = new FileReader();
                    reader.onload = function (event) {
                        var img = document.createElement('img');
                        img.src = event.target.result;
                        img.alt = '';
                        if (imgClass) {
                            img.className = imgClass;
                        }
                        container.innerHTML = '';
                        container.appendChild(img);
                    };
                    reader.readAsDataURL(file);
                });
            }

            previewImage('community-avatar-input', 'community-avatar-preview', null);
            previewImage('community-cover-input', 'community-cover-preview', 'ob-settings-cover-preview');
        })();
    </script>
@endsection
