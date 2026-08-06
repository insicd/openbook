<?php

namespace Tests\Unit\Federation\Inbox;

use App\Federation\Inbox\RemotePostObject;
use Tests\TestCase;

class RemotePostObjectTest extends TestCase
{
    public function test_it_accepts_article_video_and_image_types(): void
    {
        $this->assertTrue(RemotePostObject::isPostable('Article'));
        $this->assertTrue(RemotePostObject::isPostable('Video'));
        $this->assertTrue(RemotePostObject::isPostable('Image'));
        $this->assertTrue(RemotePostObject::isPostable(['Object', 'Article']));
        $this->assertTrue(RemotePostObject::isPostable('https://www.w3.org/ns/activitystreams#Article'));
        $this->assertFalse(RemotePostObject::isPostable('Question'));
    }

    public function test_body_falls_back_through_content_map_summary_and_html_url(): void
    {
        $this->assertSame(
            'Dal contentMap',
            RemotePostObject::body(['contentMap' => ['it' => '<p>Dal contentMap</p>']]),
        );

        $this->assertSame(
            'Riassunto',
            RemotePostObject::body(['summary' => '<p>Riassunto</p>']),
        );

        $this->assertSame(
            'https://peertube.example/w/abc',
            RemotePostObject::body([
                'type' => 'Video',
                'url' => [
                    ['type' => 'Link', 'mediaType' => 'application/x-mpegURL', 'href' => 'https://peertube.example/static/hls.m3u8'],
                    ['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://peertube.example/w/abc'],
                ],
            ]),
        );
    }

    public function test_author_matches_accepts_signer_among_attributed_actors(): void
    {
        $person = 'https://peertube.example/accounts/alice';
        $channel = 'https://peertube.example/video-channels/news';

        $this->assertTrue(RemotePostObject::authorMatches([
            ['type' => 'Person', 'id' => $person],
            ['type' => 'Group', 'id' => $channel],
        ], $person));

        $this->assertTrue(RemotePostObject::authorMatches([$person, $channel], $channel));
        $this->assertFalse(RemotePostObject::authorMatches($person, $channel));
        $this->assertSame($person, RemotePostObject::primaryAuthorUri([
            ['type' => 'Group', 'id' => $channel],
            ['type' => 'Person', 'id' => $person],
        ]));
    }

    public function test_image_attachments_extract_pixelfed_style_documents(): void
    {
        $attachments = RemotePostObject::imageAttachments([
            'type' => 'Note',
            'attachment' => [
                [
                    'type' => 'Document',
                    'mediaType' => 'image/jpeg',
                    'url' => 'https://pixelfed.example/storage/photo.jpg',
                    'name' => 'Una foto',
                ],
                [
                    'type' => 'Document',
                    'mediaType' => 'video/mp4',
                    'url' => 'https://pixelfed.example/storage/clip.mp4',
                ],
            ],
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame('https://pixelfed.example/storage/photo.jpg', $attachments[0]['url']);
        $this->assertSame('Una foto', $attachments[0]['alt']);
    }

    public function test_image_attachments_include_inline_img_from_html_content(): void
    {
        $gifUrl = 'https://mastodon.example/media_attachments/files/1/2/original/abc123.gif';

        $attachments = RemotePostObject::imageAttachments([
            'type' => 'Note',
            'content' => '<p>Guarda questo:</p><p><img src="'.$gifUrl.'" alt="GIF divertente"></p>',
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame($gifUrl, $attachments[0]['url']);
        $this->assertSame('image/gif', $attachments[0]['mime']);
        $this->assertSame('GIF divertente', $attachments[0]['alt']);
    }

    public function test_body_does_not_repeat_inline_image_url(): void
    {
        $gifUrl = 'https://mastodon.example/media/photo.gif';

        $this->assertSame(
            'Guarda questo:',
            RemotePostObject::body([
                'type' => 'Note',
                'content' => '<p>Guarda questo:</p><p><img src="'.$gifUrl.'" alt=""></p>',
            ]),
        );
    }
}
