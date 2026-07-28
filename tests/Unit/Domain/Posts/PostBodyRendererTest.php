<?php

namespace Tests\Unit\Domain\Posts;

use App\Domain\Posts\PostBodyRenderer;
use Tests\TestCase;

class PostBodyRendererTest extends TestCase
{
    public function test_it_turns_a_url_into_a_clickable_link_opening_in_a_new_tab(): void
    {
        $html = (string) PostBodyRenderer::render('Guarda questo: https://esempio.it/pagina');

        $this->assertStringContainsString(
            '<a href="https://esempio.it/pagina" class="post-link" target="_blank" rel="noopener noreferrer nofollow ugc">https://esempio.it/pagina</a>',
            $html
        );
    }

    public function test_it_strips_trailing_sentence_punctuation_from_the_link(): void
    {
        $html = (string) PostBodyRenderer::render('Vedi https://esempio.it/pagina, poi https://esempio.it/altra.');

        $this->assertStringContainsString('href="https://esempio.it/pagina"', $html);
        $this->assertStringContainsString('</a>,', $html);
        $this->assertStringContainsString('href="https://esempio.it/altra"', $html);
        $this->assertStringContainsString('</a>.', $html);
    }

    public function test_it_keeps_a_balanced_trailing_parenthesis_inside_the_link(): void
    {
        $html = (string) PostBodyRenderer::render('Fonte (https://it.wikipedia.org/wiki/Test_(disambigua))');

        $this->assertStringContainsString('href="https://it.wikipedia.org/wiki/Test_(disambigua)"', $html);
    }

    public function test_it_drops_an_unbalanced_trailing_parenthesis_from_the_link(): void
    {
        $html = (string) PostBodyRenderer::render('(vedi https://esempio.it/pagina)');

        $this->assertStringContainsString('href="https://esempio.it/pagina"', $html);
        $this->assertStringContainsString('</a>)', $html);
    }

    public function test_it_does_not_reprocess_a_hash_fragment_inside_a_url_as_a_hashtag(): void
    {
        $html = (string) PostBodyRenderer::render('https://esempio.it/pagina#sezione');

        $this->assertStringContainsString('href="https://esempio.it/pagina#sezione"', $html);
        $this->assertStringNotContainsString('class="hashtag"', $html);
    }

    public function test_it_does_not_reprocess_an_at_segment_inside_a_url_as_a_mention(): void
    {
        $html = (string) PostBodyRenderer::render('Profilo: https://esempio.it/@alice');

        $this->assertStringContainsString('href="https://esempio.it/@alice"', $html);
        $this->assertStringNotContainsString('class="mention"', $html);
    }

    public function test_it_still_renders_hashtags_and_mentions_alongside_a_url(): void
    {
        $html = (string) PostBodyRenderer::render('Vedi https://esempio.it #openbook @admin');

        $this->assertStringContainsString('class="post-link"', $html);
        $this->assertStringContainsString('class="hashtag"', $html);
        $this->assertStringContainsString('class="mention"', $html);
    }

    public function test_it_escapes_html_special_characters_around_a_url(): void
    {
        $html = (string) PostBodyRenderer::render('<script>alert(1)</script> https://esempio.it/"quote');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('href="https://esempio.it/"quote"', $html);
    }

    public function test_it_does_not_linkify_text_that_only_resembles_a_scheme(): void
    {
        $html = (string) PostBodyRenderer::render('Il rapporto costi/benefici e alto');

        $this->assertStringNotContainsString('class="post-link"', $html);
    }
}
