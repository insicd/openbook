<?php

namespace App\Federation\Serialization;

use App\Domain\Posts\Post;
use App\Domain\Posts\PostBodyRenderer;
use App\Federation\Actors\Actor;

/**
 * Create/Note indirizzati al singolo follower che ha acceso la campanella
 * sul Group. Non finiscono nell'outbox: sono solo consegne personali, cosi'
 * Mastodon (che ignora gli Announce-reblog per `Follow.notify`) riceve un
 * Create originale del Group e puo' emettere la notifica.
 */
final class GroupNotifyNoteSerializer
{
    public static function noteUri(Actor $group, Post $post, Actor $follower): string
    {
        return $group->activityPubId().'/avvisi/'.$post->id.'/'.$follower->id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function note(Actor $group, Post $post, Actor $follower): array
    {
        $postUri = NoteSerializer::postUri($post);
        $content = self::content($post, $postUri);
        $followerId = $follower->activityPubId();

        $note = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => self::noteUri($group, $post, $follower),
            'type' => 'Note',
            'attributedTo' => $group->activityPubId(),
            'content' => $content,
            'url' => $postUri,
            'published' => $post->published_at->toAtomString(),
            'to' => [$followerId],
            'cc' => [],
            'quoteUrl' => $postUri,
            'tag' => [[
                'type' => 'Mention',
                'href' => $followerId,
                'name' => '@'.$follower->handle(),
            ]],
        ];

        if (filled($post->title)) {
            $note['name'] = $post->title;
        }

        if (filled($post->content_warning)) {
            $note['summary'] = e((string) $post->content_warning);
            $note['sensitive'] = true;
        }

        return $note;
    }

    /**
     * @return array<string, mixed>
     */
    public static function create(Actor $group, Post $post, Actor $follower): array
    {
        $note = self::note($group, $post, $follower);

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $note['id'].'/attivita',
            'type' => 'Create',
            'actor' => $group->activityPubId(),
            'published' => $note['published'],
            'to' => $note['to'],
            'cc' => [],
            'object' => $note,
        ];
    }

    private static function content(Post $post, string $postUri): string
    {
        $html = (string) PostBodyRenderer::renderForFederation($post->body);

        if (filled($post->title)) {
            $html = '<p><b>'.e($post->title).'</b></p>'.$html;
        }

        if ($html === '') {
            $html = '<p></p>';
        }

        if (! str_contains($html, $postUri)) {
            $html .= '<p><a href="'.e($postUri).'">'.e($postUri).'</a></p>';
        }

        return $html;
    }
}
