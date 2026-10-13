<?php

namespace App\Federation\Serialization;

use App\Domain\Posts\Post;
use App\Domain\Posts\PostBodyRenderer;
use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorUrls;

/**
 * Create/Note del Group per i nuovi post sul wall. Mastodon tiene la
 * campanella solo in locale e non la mette nel Follow: l'Announce FEP-1b12
 * e' un boost e non genera {@code status} notification. Un Create originale
 * del Group (non un reblog), consegnato a tutti i membri remoti, entra nel
 * home feed e Mastodon puo' notificare chi ha acceso la campanella.
 */
final class GroupNotifyNoteSerializer
{
    public static function noteUri(Actor $group, Post $post): string
    {
        return $group->activityPubId().'/avvisi/'.$post->id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function note(Actor $group, Post $post): array
    {
        $post->loadMissing('community');
        $postUri = NoteSerializer::postUri($post);
        $followers = LocalActorUrls::forUsername($group->preferred_username, true)['followers'];
        $private = $post->community?->is_private === true;

        $note = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => self::noteUri($group, $post),
            'type' => 'Note',
            'attributedTo' => $group->activityPubId(),
            'content' => self::content($post, $postUri),
            'url' => $postUri,
            'published' => $post->published_at->toAtomString(),
            'to' => [$followers],
            'cc' => $private ? [] : [NoteSerializer::PUBLIC_STREAM],
            'quoteUrl' => $postUri,
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
    public static function create(Actor $group, Post $post): array
    {
        $note = self::note($group, $post);

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $note['id'].'/attivita',
            'type' => 'Create',
            'actor' => $group->activityPubId(),
            'published' => $note['published'],
            'to' => $note['to'],
            'cc' => $note['cc'],
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
