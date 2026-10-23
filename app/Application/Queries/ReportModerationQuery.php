<?php

namespace App\Application\Queries;

use App\Domain\Events\Event;
use App\Domain\Moderation\Report;
use App\Domain\Moderation\ReportTarget;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ReportModerationQuery
{
    public function listing(string $status): Builder
    {
        return Report::query()
            ->with(['reporter.profile', 'reviewer', 'targets:id,report_id,type'])
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return list<array<string, mixed>> */
    public function previews(Report $report, ?Actor $viewer): array
    {
        if ($report->isFederated()) {
            $targets = $report->targets()->get();
        } else {
            $type = $report->isCommentReport() ? ReportTarget::TYPE_COMMENT : ReportTarget::TYPE_POST;
            $id = $report->isCommentReport() ? $report->comment_id : $report->post_id;
            $targets = new Collection([new ReportTarget([
                'type' => $type, $type.'_id' => $id,
                'uri' => $id === null ? '' : route($type === 'post' ? 'posts.show' : 'comments.show', $id),
            ])]);
        }

        // Constrain the eager loads themselves: inaccessible bodies never enter the previews.
        $targets->load([
            'actor',
            'post' => fn ($query) => $query->visibleTo($viewer)->with('actor'),
            'comment' => fn ($query) => $query->whereHas('post', fn (Builder $post) => $post->visibleTo($viewer))->with('actor'),
            'event' => fn ($query) => $query->visibleTo($viewer)->with('actor'),
            'eventComment' => fn ($query) => $query->whereHas('event', fn (Builder $event) => $event->visibleTo($viewer))->with('actor'),
        ]);

        return $targets->map(function (ReportTarget $target): array {
            $relation = $target->type === ReportTarget::TYPE_EVENT_COMMENT ? 'eventComment' : $target->type;
            $subject = $target->getRelation($relation);
            $column = $target->type.'_id';
            $missing = $target->unavailable || $target->$column === null || $subject?->status === 'deleted';
            $state = $missing ? 'missing' : ($subject === null ? 'restricted' : 'available');
            $preview = ['type' => $target->type, 'uri' => $target->uri, 'state' => $state,
                'author' => null, 'title' => null, 'body' => null, 'url' => null];
            if ($state !== 'available') {
                return $preview;
            }

            $preview['author'] = $subject instanceof Actor ? $subject->preferred_username : $subject->actor?->preferred_username;
            $preview['title'] = $subject instanceof Actor ? ($subject->name ?: $subject->preferred_username)
                : ($subject instanceof Event ? $subject->name : ($subject->title ?? null));
            $preview['body'] = $subject instanceof Actor ? null
                : ($subject instanceof Event ? ($subject->content ?: $subject->summary) : $subject->body);
            $preview['url'] = match ($target->type) {
                ReportTarget::TYPE_ACTOR => route('profile.show', $subject->preferred_username),
                ReportTarget::TYPE_POST => route('posts.show', $subject),
                ReportTarget::TYPE_COMMENT => route('comments.show', $subject),
                ReportTarget::TYPE_EVENT => route('events.show', $subject),
                ReportTarget::TYPE_EVENT_COMMENT => route('event-comments.show', $subject),
            };

            return $preview;
        })->all();
    }
}
