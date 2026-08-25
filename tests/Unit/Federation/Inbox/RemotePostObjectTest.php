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

    public function test_title_comes_from_name_and_is_stripped_from_openbook_content(): void
    {
        $document = [
            'name' => 'Il titolo',
            'content' => '<p><b>Il titolo</b></p><p>Il corpo.</p>',
        ];

        $this->assertSame('Il titolo', RemotePostObject::title($document));
        $this->assertSame('Il corpo.', RemotePostObject::body($document));
    }

    public function test_title_is_recovered_from_a_leading_b_paragraph_without_name(): void
    {
        $document = [
            'content' => '<p><b>Vecchio titolo</b></p><p>Solo content.</p>',
        ];

        $this->assertSame('Vecchio titolo', RemotePostObject::title($document));
        $this->assertSame('Solo content.', RemotePostObject::body($document));
    }

    public function test_a_leading_strong_paragraph_is_not_treated_as_a_title(): void
    {
        $document = [
            'content' => '<p><strong>Non e\' un titolo</strong></p><p>Corpo.</p>',
        ];

        $this->assertNull(RemotePostObject::title($document));
        $this->assertStringContainsString("Non e' un titolo", RemotePostObject::body($document));
        $this->assertStringContainsString('Corpo.', RemotePostObject::body($document));
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

    public function test_media_attachments_include_mastodon_gif_as_mp4_document(): void
    {
        $mp4Url = 'https://mastodon.example/media_attachments/files/1/2/original/abc.mp4';

        $attachments = RemotePostObject::mediaAttachments([
            'type' => 'Note',
            'content' => '',
            'attachment' => [
                [
                    'type' => 'Document',
                    'mediaType' => 'video/mp4',
                    'url' => $mp4Url,
                    'name' => 'GIF animata',
                ],
            ],
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame($mp4Url, $attachments[0]['url']);
        $this->assertSame('video/mp4', $attachments[0]['mime']);
        $this->assertSame('GIF animata', $attachments[0]['alt']);
    }

    public function test_media_attachments_include_mastodon_audio_document(): void
    {
        $mp3Url = 'https://mastodon.example/media_attachments/files/1/2/original/abc.mp3';

        $attachments = RemotePostObject::mediaAttachments([
            'type' => 'Note',
            'content' => '<p>Clip audio</p>',
            'attachment' => [
                [
                    'type' => 'Document',
                    'mediaType' => 'audio/mpeg',
                    'url' => $mp3Url,
                    'name' => 'Antistamina',
                ],
            ],
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame($mp3Url, $attachments[0]['url']);
        $this->assertSame('audio/mpeg', $attachments[0]['mime']);
        $this->assertSame('Antistamina', $attachments[0]['alt']);
    }

    public function test_media_attachments_include_activitystreams_audio_object(): void
    {
        $oggUrl = 'https://mastodon.example/media/clip.ogg';

        $attachments = RemotePostObject::mediaAttachments([
            'type' => 'Audio',
            'url' => $oggUrl,
            'mediaType' => 'audio/ogg',
            'name' => 'Registrazione',
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame($oggUrl, $attachments[0]['url']);
        $this->assertSame('audio/ogg', $attachments[0]['mime']);
    }

    public function test_image_attachments_exclude_audio_documents(): void
    {
        $attachments = RemotePostObject::imageAttachments([
            'type' => 'Note',
            'attachment' => [
                [
                    'type' => 'Document',
                    'mediaType' => 'audio/mpeg',
                    'url' => 'https://mastodon.example/media/clip.mp3',
                ],
                [
                    'type' => 'Document',
                    'mediaType' => 'image/jpeg',
                    'url' => 'https://mastodon.example/media/photo.jpg',
                ],
            ],
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame('https://mastodon.example/media/photo.jpg', $attachments[0]['url']);
    }

    public function test_media_attachments_include_inline_video_from_html_content(): void
    {
        $mp4Url = 'https://mastodon.example/media/loop.mp4';

        $attachments = RemotePostObject::mediaAttachments([
            'type' => 'Note',
            'content' => '<p><video loop muted playsinline><source src="'.$mp4Url.'" type="video/mp4"></video></p>',
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame($mp4Url, $attachments[0]['url']);
        $this->assertSame('video/mp4', $attachments[0]['mime']);
    }

    public function test_media_attachments_deduplicate_wordpress_preview_and_full_size(): void
    {
        $full = 'https://wp.example/wp-content/uploads/2026/08/cover-1024x572.jpg';
        $thumb = 'https://wp.example/wp-content/uploads/2026/08/cover-150x150.jpg';

        $attachments = RemotePostObject::mediaAttachments([
            'type' => 'Note',
            'attachment' => [
                ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => $full],
                ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => $thumb],
            ],
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame($full, $attachments[0]['url']);
    }

    public function test_media_attachments_deduplicate_mastodon_original_and_small(): void
    {
        $original = 'https://mastodon.example/media_attachments/files/1/2/original/abc.png';
        $small = 'https://mastodon.example/media_attachments/files/1/2/small/abc.png';

        $attachments = RemotePostObject::mediaAttachments([
            'type' => 'Note',
            'attachment' => [
                ['type' => 'Document', 'mediaType' => 'image/png', 'url' => $small],
                ['type' => 'Document', 'mediaType' => 'image/png', 'url' => $original],
            ],
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame($original, $attachments[0]['url']);
    }

    public function test_media_attachments_keep_distinct_images(): void
    {
        $attachments = RemotePostObject::mediaAttachments([
            'type' => 'Note',
            'attachment' => [
                ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://pixelfed.example/a.jpg'],
                ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://pixelfed.example/b.jpg'],
            ],
        ]);

        $this->assertCount(2, $attachments);
    }

    public function test_media_attachments_deduplicate_attachment_and_inline_preview(): void
    {
        $full = 'https://wp.example/wp-content/uploads/photo-1024x768.jpg';
        $inline = 'https://wp.example/wp-content/uploads/photo-300x200.jpg';

        $attachments = RemotePostObject::mediaAttachments([
            'type' => 'Note',
            'attachment' => [
                ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => $full],
            ],
            'content' => '<p><img src="'.$inline.'" alt="Anteprima"></p>',
        ]);

        $this->assertCount(1, $attachments);
        $this->assertSame($full, $attachments[0]['url']);
        $this->assertSame('Anteprima', $attachments[0]['alt']);
    }
}
