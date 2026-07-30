<?php

namespace Tests\Unit\Domain\Posts;

use App\Domain\Posts\VideoEmbed;
use App\Domain\Posts\VideoEmbedFinder;
use Tests\TestCase;

class VideoEmbedFinderTest extends TestCase
{
    public function test_it_embeds_a_youtube_watch_url(): void
    {
        $embed = VideoEmbedFinder::first('Guarda https://www.youtube.com/watch?v=dQw4w9WgXcQ davvero.');

        $this->assertNotNull($embed);
        $this->assertSame(VideoEmbed::PROVIDER_YOUTUBE, $embed->provider);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed->embedUrl);
    }

    public function test_it_embeds_a_youtu_be_short_url(): void
    {
        $embed = VideoEmbedFinder::first('https://youtu.be/dQw4w9WgXcQ');

        $this->assertNotNull($embed);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed->embedUrl);
    }

    public function test_it_embeds_a_youtube_shorts_url(): void
    {
        $embed = VideoEmbedFinder::first('https://www.youtube.com/shorts/dQw4w9WgXcQ');

        $this->assertNotNull($embed);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed->embedUrl);
    }

    public function test_it_embeds_a_peertube_watch_url(): void
    {
        $embed = VideoEmbedFinder::first('Video: https://framatube.org/w/coLQEg9FZQEMH5AbhZCoXN bello');

        $this->assertNotNull($embed);
        $this->assertSame(VideoEmbed::PROVIDER_PEERTUBE, $embed->provider);
        $this->assertSame('https://framatube.org/videos/embed/coLQEg9FZQEMH5AbhZCoXN', $embed->embedUrl);
    }

    public function test_it_embeds_a_peertube_videos_watch_uuid_url(): void
    {
        $uuid = '9c9de5e8-0a1e-484a-b099-e80766180a6d';
        $embed = VideoEmbedFinder::first("https://peertube.example/videos/watch/{$uuid}");

        $this->assertNotNull($embed);
        $this->assertSame("https://peertube.example/videos/embed/{$uuid}", $embed->embedUrl);
    }

    public function test_it_uses_only_the_first_video_link_when_several_are_present(): void
    {
        $embed = VideoEmbedFinder::first(
            'Prima https://youtu.be/AAAAAAAAAAA poi https://framatube.org/w/coLQEg9FZQEMH5AbhZCoXN'
        );

        $this->assertNotNull($embed);
        $this->assertSame(VideoEmbed::PROVIDER_YOUTUBE, $embed->provider);
        $this->assertSame('https://www.youtube-nocookie.com/embed/AAAAAAAAAAA', $embed->embedUrl);
    }

    public function test_it_ignores_non_video_urls(): void
    {
        $this->assertNull(VideoEmbedFinder::first('Solo un link https://esempio.it/articolo'));
    }

    public function test_it_ignores_generic_w_paths_that_are_too_short_to_be_peertube(): void
    {
        $this->assertNull(VideoEmbedFinder::first('https://esempio.it/w/short'));
    }

    public function test_it_strips_trailing_punctuation_from_the_video_url(): void
    {
        $embed = VideoEmbedFinder::first('Guarda https://youtu.be/dQw4w9WgXcQ.');

        $this->assertNotNull($embed);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed->embedUrl);
    }
}
