<?php

namespace Tests\Unit\Domain\Posts;

use App\Domain\Posts\PostBodyUrlFinder;
use Tests\TestCase;

class PostBodyUrlFinderTest extends TestCase
{
    public function test_preview_ignores_markdown_mentions_and_hashtags(): void
    {
        $body = 'https://news.example/story?source=feed'
            .' Posted into [@publisher](https://social.example/@publisher)'
            .' [#music](https://social.example/tags/music)';

        $this->assertSame(
            ['https://news.example/story?source=feed'],
            PostBodyUrlFinder::previewCandidates($body),
        );
    }

    public function test_a_normal_markdown_link_remains_a_preview_candidate(): void
    {
        $this->assertSame(
            ['https://news.example/story'],
            PostBodyUrlFinder::previewCandidates('[Read the story](https://news.example/story)'),
        );
    }

    public function test_two_article_links_still_disqualify_the_post(): void
    {
        $this->assertCount(
            2,
            PostBodyUrlFinder::previewCandidates(
                '[First article](https://news.example/one) https://news.example/two',
            ),
        );
    }
}
