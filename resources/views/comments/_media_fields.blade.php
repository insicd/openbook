@php
    $inputId = $inputId ?? 'comment-images';
    $altId = $altId ?? 'comment-alt';
    $maxAttachments = (int) config('openbook.media.max_attachments_per_post');
@endphp

<div class="ob-field" style="margin-top:0.6rem">
    <label for="{{ $inputId }}">{{ __('openbook.composer.images_label') }}</label>
    <input type="file" id="{{ $inputId }}" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
    <p class="ob-field__help">{{ __('openbook.composer.images_help', ['count' => $maxAttachments]) }}</p>
</div>
<div class="ob-field">
    <label for="{{ $altId }}">{{ __('openbook.composer.alt_label') }}</label>
    <input type="text" id="{{ $altId }}" name="alt_texts[]" maxlength="1000" value="{{ old('alt_texts.0') }}">
    <p class="ob-field__help">{{ __('openbook.composer.alt_help') }}</p>
</div>
