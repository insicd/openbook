<?php

namespace App\Http\Requests\Messages;

use App\Application\Services\QuotedActorResolver;
use App\Application\Services\QuotedEventResolver;
use App\Application\Services\QuotedPostResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:5000'],
            'quoted_post_id' => ['nullable', 'uuid'],
            'quoted_actor_id' => ['nullable', 'uuid'],
            'quoted_event_id' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $body = trim((string) $this->input('body', ''));
            $quotedId = $this->input('quoted_post_id');
            $quotedId = is_string($quotedId) && $quotedId !== '' ? $quotedId : null;
            $quoted = app(QuotedPostResolver::class)->resolveForShare($this->user()?->actor, $quotedId);

            $actorId = $this->input('quoted_actor_id');
            $actorId = is_string($actorId) && $actorId !== '' ? $actorId : null;
            $quotedActor = app(QuotedActorResolver::class)->resolveForShare($this->user()?->actor, $actorId);

            $eventId = $this->input('quoted_event_id');
            $eventId = is_string($eventId) && $eventId !== '' ? $eventId : null;
            $quotedEvent = app(QuotedEventResolver::class)->resolveForShare($this->user()?->actor, $eventId);

            if ($quotedId !== null && $quoted === null) {
                $validator->errors()->add('quoted_post_id', __('openbook.composer.quote_unavailable'));
            }

            if ($actorId !== null && $quotedActor === null) {
                $validator->errors()->add('quoted_actor_id', __('openbook.messages.errors.profile_unavailable'));
            }

            if ($eventId !== null && $quotedEvent === null) {
                $validator->errors()->add('quoted_event_id', __('openbook.messages.errors.event_unavailable'));
            }

            if (collect([$quoted, $quotedActor, $quotedEvent])->filter()->count() > 1) {
                $validator->errors()->add('quoted_actor_id', __('openbook.messages.errors.profile_unavailable'));
            }

            if ($body === '' && $quoted === null && $quotedActor === null && $quotedEvent === null) {
                $validator->errors()->add('body', __('openbook.messages.errors.empty_body'));
            }
        });
    }
}
