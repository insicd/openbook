<?php

namespace App\Http\Controllers;

use App\Application\Services\EventCommentComposer;
use App\Application\Services\EventCommentSoftDeleter;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\EventCommentSerializer;
use App\Http\Requests\Events\StoreEventCommentRequest;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventCommentController extends Controller
{
    public function __construct(
        private readonly EventCommentComposer $composer,
        private readonly EventCommentSoftDeleter $deleter,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function show(Request $request, EventComment $comment): RedirectResponse|JsonResponse
    {
        $comment->loadMissing('event');
        $viewer = ActivityPubNegotiation::wantsActivityPub($request) ? null : auth()->user()?->actor;
        abort_unless(Event::query()->whereKey($comment->event_id)->visibleTo($viewer)->exists(), 404);

        if (ActivityPubNegotiation::wantsActivityPub($request)) {
            return ActivityPubNegotiation::response(
                $comment->isPublished()
                    ? EventCommentSerializer::serialize($comment)
                    : EventCommentSerializer::tombstone($comment)
            );
        }

        return redirect(route('events.show', $comment->event_id).'#commento-evento-'.$comment->id);
    }

    public function store(StoreEventCommentRequest $request, Event $event): RedirectResponse
    {
        $viewer = $request->user()->actor;
        abort_unless(Event::query()->whereKey($event->id)->visibleTo($viewer)->exists(), 404);
        abort_unless($event->isOpenForInteractions(), 404);
        $data = $request->validated();
        $parent = filled($data['parent_comment_id'] ?? null)
            ? EventComment::query()->findOrFail($data['parent_comment_id'])
            : null;
        $images = $request->file('images', []);
        $images = is_array($images) ? $images : ($images !== null ? [$images] : []);
        $comment = $this->composer->compose(
            $viewer,
            $event,
            $data['body'],
            $parent,
            array_values($images),
            $data['alt_texts'] ?? [],
        );

        return redirect(route('events.show', $event).'#commento-evento-'.$comment->id);
    }

    public function destroy(EventComment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);
        $comment->load('mentions.actor', 'actor', 'event.actor', 'parent.actor');
        $directTargets = $comment->mentions->pluck('actor')
            ->push($comment->parent?->actor ?? $comment->event->actor)
            ->filter()
            ->unique('id')
            ->values()
            ->all();
        $this->deleter->delete($comment);
        $this->delivery->deliverContent($comment, ActivitySerializer::delete($comment), $directTargets);

        return redirect(route('events.show', $comment->event_id).'#commenti')
            ->with('status', __('openbook.comments.deleted'));
    }
}
