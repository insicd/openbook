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
}
