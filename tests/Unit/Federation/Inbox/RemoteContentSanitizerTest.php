<?php

namespace Tests\Unit\Federation\Inbox;

use App\Federation\Inbox\RemoteContentSanitizer;
use Tests\TestCase;

class RemoteContentSanitizerTest extends TestCase
{
    public function test_it_preserves_href_from_html_anchors_as_labeled_markdown(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<p>Vedi <a href="https://ciccio.it/pagina">testo link</a> qui.</p>'
        );

        $this->assertSame('Vedi [testo link](https://ciccio.it/pagina) qui.', $text);
    }

    public function test_it_keeps_bare_url_when_anchor_label_matches_href(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<a href="https://esempio.it/x">https://esempio.it/x</a>'
        );

        $this->assertSame('https://esempio.it/x', $text);
    }

    public function test_it_reads_href_regardless_of_attribute_order(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<a class="external" rel="nofollow" href="https://fonte.example/articolo">la fonte</a>'
        );

        $this->assertSame('[la fonte](https://fonte.example/articolo)', $text);
    }

    public function test_it_strips_nested_markup_inside_anchor_label(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<a href="https://esempio.it"><strong>grassetto</strong></a>'
        );

        $this->assertSame('[grassetto](https://esempio.it)', $text);
    }

    public function test_it_drops_non_http_schemes_but_keeps_label(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<a href="javascript:alert(1)">clicca</a>'
        );

        $this->assertSame('clicca', $text);
        $this->assertStringNotContainsString('javascript:', $text);
    }

    public function test_it_decodes_html_entities_in_href_and_label(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<a href="https://esempio.it/?q=a&amp;b=1">A &amp; B</a>'
        );

        $this->assertSame('[A & B](https://esempio.it/?q=a&b=1)', $text);
    }

    public function test_it_collapses_remote_hashtag_anchors_to_plain_hashtags(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<p>Ciao <a href="https://mastodon.example/tags/openbook" class="mention hashtag" rel="tag">#<span>openbook</span></a>!</p>'
        );

        $this->assertSame('Ciao #openbook!', $text);
        $this->assertStringNotContainsString('mastodon.example', $text);
        $this->assertStringNotContainsString('[#openbook]', $text);
    }

    public function test_it_collapses_tag_path_anchors_without_hash_in_label(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<a href="https://pixelfed.example/discover/tags/foto">foto</a>'
        );

        $this->assertSame('#foto', $text);
    }

    public function test_it_collapses_remote_mention_anchors_to_federated_handles(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<p>Ciao <span class="h-card"><a href="https://mastodon.example/@alice" class="u-url mention">@<span>alice</span></a></span>!</p>'
        );

        $this->assertSame('Ciao @alice@mastodon.example!', $text);
        $this->assertStringNotContainsString('[@alice]', $text);
        $this->assertStringNotContainsString('](https://mastodon.example', $text);
    }

    public function test_it_keeps_local_mentions_without_domain_suffix(): void
    {
        config(['openbook.domain' => 'openbook.test']);

        $text = RemoteContentSanitizer::toPlainText(
            '<a href="https://openbook.test/@mario" class="mention">@mario</a>'
        );

        $this->assertSame('@mario', $text);
    }

    public function test_it_extracts_inline_gif_images_from_html(): void
    {
        $images = RemoteContentSanitizer::extractInlineImages(
            '<p><img src="https://cdn.example/anim.gif" alt="loop"></p>'
        );

        $this->assertCount(1, $images);
        $this->assertSame('https://cdn.example/anim.gif', $images[0]['url']);
        $this->assertSame('image/gif', $images[0]['mime']);
        $this->assertSame('loop', $images[0]['alt']);
    }

    public function test_it_removes_inline_images_from_plain_text_body(): void
    {
        $text = RemoteContentSanitizer::toPlainText(
            '<p>Testo</p><img src="https://cdn.example/anim.gif" alt="">'
        );

        $this->assertSame('Testo', $text);
        $this->assertStringNotContainsString('cdn.example', $text);
    }
}
