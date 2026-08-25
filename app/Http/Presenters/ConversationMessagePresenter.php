<?php

namespace App\Http\Presenters;

use App\Domain\Posts\Post;
use App\Domain\Posts\PostBodyRenderer;
use App\Federation\Actors\Actor;

/**
 * Serializza un messaggio diretto per feed JSON / risposte Ajax.
 */
final class ConversationMessagePresenter
{
    /**
     * @return array{id: string, mine: bool, author_name: string, published_at: string, published_label: string, body_html: string, quote_html: string}
     */
    public function toArray(Post $message, Actor $viewer): array
    {
        $message->loadMissing([
            'actor.user.profile',
            'quotedPost.actor.user.profile',
            'quotedPost.community.actor',
            'quotedPost.media.thumbnail',
            'quotedPost.hashtags',
            'quotedActor.user.profile',
        ]);

        $quoteHtml = '';

        if ($message->quotedPost !== null) {
            $quoteHtml = view('messages._quote', ['quotedPost' => $message->quotedPost])->render();
        } elseif ($message->quotedActor !== null) {
            $quoteHtml = view('messages._profile', [
                'quotedActor' => $message->quotedActor,
                'showFollow' => true,
            ])->render();
        }

        return [
            'id' => $message->id,
            'mine' => $message->actor_id === $viewer->id,
            'author_name' => $message->actor->displayName(),
            'published_at' => $message->published_at->toIso8601String(),
            'published_label' => $message->published_at->format('d/m/Y H:i'),
            'body_html' => (string) PostBodyRenderer::render($message->body),
            'quote_html' => $quoteHtml,
        ];
    }

    /**
     * @param  iterable<int, Post>  $messages
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $messages, Actor $viewer): array
    {
        $items = [];

        foreach ($messages as $message) {
            $items[] = $this->toArray($message, $viewer);
        }

        return $items;
    }
}
