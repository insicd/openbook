        <h2 style="margin-top:1.5rem;font-size:1.1rem">{{ __('openbook.admin.settings.limits_title') }}</h2>

        <div class="ob-field" style="margin-top:1rem">
            <label for="post_max_length">{{ __('openbook.admin.settings.post_max_length') }}</label>
            <input type="number" id="post_max_length" name="post_max_length" value="{{ old('post_max_length', $postMaxLength) }}" required min="100" max="50000">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="comment_max_length">{{ __('openbook.admin.settings.comment_max_length') }}</label>
            <input type="number" id="comment_max_length" name="comment_max_length" value="{{ old('comment_max_length', $commentMaxLength) }}" required min="100" max="10000">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="media_max_size_kb">{{ __('openbook.admin.settings.media_max_size_kb') }}</label>
            <input type="number" id="media_max_size_kb" name="media_max_size_kb" value="{{ old('media_max_size_kb', $mediaMaxSizeKb) }}" required min="100" max="51200">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="media_max_attachments">{{ __('openbook.admin.settings.media_max_attachments') }}</label>
            <input type="number" id="media_max_attachments" name="media_max_attachments" value="{{ old('media_max_attachments', $mediaMaxAttachments) }}" required min="1" max="20">
        </div>

        <h2 style="margin-top:1.5rem;font-size:1.1rem">{{ __('openbook.admin.settings.trending_title') }}</h2>

        <div class="ob-field" style="margin-top:1rem">
            <label for="trending_days">{{ __('openbook.admin.settings.trending_days') }}</label>
            <input type="number" id="trending_days" name="trending_days" value="{{ old('trending_days', $trendingDays) }}" required min="1" max="365">
            <p class="ob-field__help">{{ __('openbook.admin.settings.trending_days_help') }}</p>
        </div>
