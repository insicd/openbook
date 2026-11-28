<?php

namespace Tests\Unit\Federation\Inbox;

use App\Federation\Inbox\PeerTubeVideo;
use PHPUnit\Framework\TestCase;

class PeerTubeVideoTest extends TestCase
{
    public function test_it_requires_a_peertube_video_with_an_embed_on_the_same_origin(): void
    {
        $uri = 'https://peertube.example/videos/watch/canonical-id';
        $author = 'https://peertube.example/accounts/creator';
        $embed = 'https://peertube.example/videos/embed/embedded-id';
        $video = ['type' => 'Video', '@context' => ['pt' => 'https://joinpeertube.org/ns#'], 'embedUrl' => $embed];

        $this->assertSame(['embedUrl' => $embed, 'posterUrl' => null], PeerTubeVideo::fromDocument($video, $uri, $author));

        foreach ([
            ['type' => 'Note'],
            ['@context' => 'https://www.w3.org/ns/activitystreams'],
            ['embedUrl' => 'http://peertube.example/videos/embed/embedded-id'],
            ['embedUrl' => 'https://other.example/videos/embed/embedded-id'],
            ['embedUrl' => 'https://peertube.example:8443/videos/embed/embedded-id'],
            ['embedUrl' => 'https://peertube.example/videos/embed/embedded-id/extra'],
            ['embedUrl' => 'https://peertube.example/w/embedded-id'],
        ] as $overrides) {
            $this->assertNull(PeerTubeVideo::fromDocument(array_replace($video, $overrides), $uri, $author));
        }

        $this->assertNull(PeerTubeVideo::fromDocument($video, $uri, 'https://other.example/accounts/creator'));
        $this->assertNull(PeerTubeVideo::fromDocument(['type' => 'Video', '@context' => $video['@context']], $uri, $author));
    }

    public function test_it_keeps_the_peer_tube_cover_when_embedding_is_restricted(): void
    {
        $poster = 'https://cdn.example/poster.png';
        $document = [
            'type' => 'Video',
            '@context' => ['pt' => 'https://joinpeertube.org/ns#'],
            'embedUrl' => null,
            'icon' => ['type' => 'Image', 'url' => $poster],
        ];
        $author = 'https://peertube.example/accounts/creator';

        $this->assertSame(['embedUrl' => null, 'posterUrl' => $poster], PeerTubeVideo::fromDocument($document, 'https://peertube.example/videos/watch/canonical-id', $author));
        $this->assertNull(PeerTubeVideo::fromDocument($document, 'https://peertube.example/posts/canonical-id', $author));
    }
}
