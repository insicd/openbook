<?php

namespace App\Http\Requests\Events;

use App\Domain\Events\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $imageTypes = array_values(array_filter(
            (array) config('openbook.media.allowed_mime_types'),
            fn (string $mime): bool => str_starts_with($mime, 'image/'),
        ));

        return [
            'name' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:'.(int) config('openbook.events.max_content_length', 20000)],
            'cover' => ['nullable', 'file', 'mimetypes:'.implode(',', $imageTypes), 'max:'.(int) config('openbook.media.max_size_kb')],
            'cover_alt' => ['nullable', 'string', 'max:1000'],
            'sensitive' => ['nullable', 'boolean'],
            'start_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'end_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:start_at'],
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'mode' => ['required', Rule::in(['physical', 'online', 'hybrid'])],
            'participation_url' => ['nullable', 'url:http,https', 'max:2048'],
            'location_id' => ['nullable', 'integer', 'exists:geo_cities,geoname_id'],
            'location_label' => ['nullable', 'string', 'max:600'],
            'venue' => ['nullable', 'string', 'max:500'],
            'address' => ['nullable', 'string', 'max:2000'],
            'join_mode' => ['required', Rule::in(['free', 'restricted', 'external'])],
            'visibility' => ['required', Rule::in([Event::VISIBILITY_PUBLIC, Event::VISIBILITY_UNLISTED])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mode = (string) $this->input('mode');
            $joinMode = (string) $this->input('join_mode');
            $participationUrl = trim((string) $this->input('participation_url'));

            if (in_array($mode, ['online', 'hybrid'], true) || $joinMode === 'external') {
                if ($participationUrl === '') {
                    $validator->errors()->add('participation_url', __('openbook.events.composer.errors.participation_url_required'));
                }
            }

            if ($participationUrl !== '' && ! str_starts_with(strtolower($participationUrl), 'https://')) {
                $validator->errors()->add('participation_url', __('openbook.events.composer.errors.participation_url_https'));
            }

            if (in_array($mode, ['physical', 'hybrid'], true)
                && blank($this->input('location_id'))
                && blank($this->input('venue'))
                && blank($this->input('address'))) {
                $validator->errors()->add('location_id', __('openbook.events.composer.errors.physical_location_required'));
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('openbook.events.composer.name'),
            'content' => __('openbook.events.composer.description'),
            'cover' => __('openbook.events.composer.cover'),
            'start_at' => __('openbook.events.composer.start'),
            'end_at' => __('openbook.events.composer.end'),
            'timezone' => __('openbook.events.composer.timezone'),
            'participation_url' => __('openbook.events.composer.participation_url'),
            'location_id' => __('openbook.events.composer.city'),
        ];
    }
}
