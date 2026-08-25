<?php

namespace Tests\Unit\Domain\Posts;

use App\Domain\Posts\PostBodyRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class PostBodyRendererTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_it_turns_a_url_into_a_clickable_link_opening_in_a_new_tab(): void
    {
        $html = (string) PostBodyRenderer::render('Guarda questo: https://esempio.it/pagina');

        $this->assertStringContainsString(
            'href="https://esempio.it/pagina"',
            $html
        );
        $this->assertStringContainsString('class="post-link"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow ugc"', $html);
    }

    public function test_it_renders_a_labeled_markdown_link_with_safe_attributes(): void
    {
        $html = (string) PostBodyRenderer::render('Vedi [testo link](https://ciccio.it/pagina) subito.');

        $this->assertStringContainsString('href="https://ciccio.it/pagina"', $html);
        $this->assertStringContainsString('class="post-link"', $html);
        $this->assertStringContainsString('>testo link</a>', $html);
        $this->assertStringNotContainsString('[testo link]', $html);
        $this->assertStringContainsString('subito.', $html);
    }

    public function test_it_does_not_treat_javascript_markdown_links_as_anchors(): void
    {
        $html = (string) PostBodyRenderer::render('[x](javascript:alert(1))');

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('class="post-link"', $html);
        $this->assertStringContainsString('x', $html);
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

    public function test_it_strips_raw_html_from_the_body(): void
    {
        $html = (string) PostBodyRenderer::render('ciao <script>alert(1)</script> mondo https://esempio.it/path');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('href="https://esempio.it/path"', $html);
        $this->assertStringContainsString('ciao', $html);
        $this->assertStringContainsString('mondo', $html);
    }

    public function test_it_does_not_linkify_text_that_only_resembles_a_scheme(): void
    {
        $html = (string) PostBodyRenderer::render('Il rapporto costi/benefici e alto');

        $this->assertStringNotContainsString('class="post-link"', $html);
    }

    public function test_apostrophes_are_preserved_without_fake_hashtags(): void
    {
        $html = (string) PostBodyRenderer::render("L'amico di Lucia e l'altra storia");

        $this->assertStringContainsString("L'amico", $html);
        $this->assertStringContainsString("l'altra", $html);
        $this->assertStringNotContainsString('class="hashtag"', $html);
        $this->assertStringNotContainsString('>#039<', $html);
    }

    public function test_numeric_html_entities_are_not_parsed_as_hashtags(): void
    {
        $html = (string) PostBodyRenderer::render('test &#039; entity');

        $this->assertStringNotContainsString('class="hashtag"', $html);
        $this->assertStringNotContainsString('>#039<', $html);
    }

    public function test_soft_line_breaks_become_br_inside_a_paragraph(): void
    {
        $html = (string) PostBodyRenderer::render("prima\nseconda\r\nterza");

        $this->assertStringContainsString('<p>prima<br>seconda<br>terza</p>', $html);
    }

    public function test_blank_lines_become_separate_paragraphs(): void
    {
        $html = (string) PostBodyRenderer::render("uno\n\ndue");

        $this->assertStringContainsString('<p>uno</p>', $html);
        $this->assertStringContainsString('<p>due</p>', $html);
    }

    public function test_it_renders_common_markdown_blocks_and_emphasis(): void
    {
        $html = (string) PostBodyRenderer::render("## Titolo\n\n**grassetto** e *corsivo*\n\n- uno\n- due\n\n`codice`\n\n> citazione");

        $this->assertStringContainsString('<h2>Titolo</h2>', $html);
        $this->assertStringContainsString('<strong>grassetto</strong>', $html);
        $this->assertStringContainsString('<em>corsivo</em>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>uno</li>', $html);
        $this->assertStringContainsString('<code>codice</code>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
    }

    public function test_it_does_not_render_markdown_images(): void
    {
        $html = (string) PostBodyRenderer::render('Ciao ![x](https://evil.test/a.png) mondo');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('evil.test', $html);
        $this->assertStringContainsString('Ciao', $html);
        $this->assertStringContainsString('mondo', $html);
    }

    public function test_labeled_hashtag_links_from_remote_posts_point_to_local_hashtag_pages(): void
    {
        $html = (string) PostBodyRenderer::render(
            'Guarda [#openbook](https://mastodon.example/tags/openbook) qui.'
        );

        $this->assertStringContainsString(
            'href="'.e(route('hashtags.show', 'openbook')).'"',
            $html
        );
        $this->assertStringContainsString('class="hashtag"', $html);
        $this->assertStringContainsString('rel="tag"', $html);
        $this->assertStringContainsString('>#openbook</a>', $html);
        $this->assertStringNotContainsString('mastodon.example', $html);
        $this->assertStringNotContainsString('class="post-link"', $html);
    }

    public function test_labeled_mention_links_from_remote_posts_point_to_local_actor_profiles(): void
    {
        $remote = $this->createRemoteActor('alice', 'mastodon.example');

        $html = (string) PostBodyRenderer::render(
            'Ciao [@alice](https://mastodon.example/@alice)!'
        );

        $this->assertStringContainsString(
            'href="'.e($remote->profileUrl()).'"',
            $html
        );
        $this->assertStringContainsString('class="mention"', $html);
        $this->assertStringContainsString('>@alice@mastodon.example</a>', $html);
        $this->assertStringNotContainsString('mastodon.example/@alice', $html);
        $this->assertStringNotContainsString('class="post-link"', $html);
    }

    public function test_federated_mention_handles_point_to_cached_remote_profiles(): void
    {
        $remote = $this->createRemoteActor('bob', 'social.example');

        $html = (string) PostBodyRenderer::render('Saluti @bob@social.example');

        $this->assertStringContainsString(
            'href="'.e($remote->profileUrl()).'"',
            $html
        );
        $this->assertStringContainsString('>@bob@social.example</a>', $html);
    }

    public function test_federation_html_mentions_use_activitypub_ids_not_local_actor_pages(): void
    {
        $remote = $this->createRemoteActor('nuke', 'openb.app');

        $html = (string) PostBodyRenderer::renderForFederation('Ciao @nuke@openb.app');

        $this->assertStringContainsString(
            'href="'.e($remote->activityPubId()).'"',
            $html
        );
        $this->assertStringContainsString('>@nuke@openb.app</a>', $html);
        $this->assertStringNotContainsString($remote->profileUrl(), $html);
        $this->assertStringNotContainsString('/attori/', $html);
    }

    public function test_federation_html_keeps_remote_href_for_unknown_labeled_mentions(): void
    {
        $html = (string) PostBodyRenderer::renderForFederation(
            'Ciao [@nova](https://mastodon.example/@nova)!'
        );

        $this->assertStringContainsString('href="https://mastodon.example/@nova"', $html);
        $this->assertStringNotContainsString('search.create', $html);
        $this->assertStringNotContainsString('/cerca', $html);
    }

    public function test_federation_html_hashtags_use_rel_tag_so_remotes_do_not_preview_the_tag_page(): void
    {
        $html = (string) PostBodyRenderer::renderForFederation('Oggi #openbook e #fediverso.');

        $tagUrl = e(route('hashtags.show', 'openbook'));

        $this->assertStringContainsString('href="'.$tagUrl.'"', $html);
        $this->assertStringContainsString('class="mention hashtag"', $html);
        $this->assertStringContainsString('rel="tag"', $html);
        $this->assertStringContainsString('>#<span>openbook</span></a>', $html);
        $this->assertStringContainsString('>#<span>fediverso</span></a>', $html);
        $this->assertStringNotContainsString('class="post-link"', $html);
    }

    public function test_unknown_remote_mentions_fall_back_to_search(): void
    {
        $html = (string) PostBodyRenderer::render('Saluti @sconosciuta@lontano.example');

        $this->assertStringContainsString(
            'href="'.e(route('search.create', ['q' => 'sconosciuta@lontano.example'])).'"',
            $html
        );
        $this->assertStringContainsString('class="mention"', $html);
    }

    public function test_unknown_labeled_mentions_search_with_federated_handle_from_href(): void
    {
        $html = (string) PostBodyRenderer::render(
            'Ciao [@nova](https://mastodon.example/@nova)!'
        );

        $this->assertStringContainsString(
            'href="'.e(route('search.create', ['q' => 'nova@mastodon.example'])).'"',
            $html
        );
        $this->assertStringNotContainsString('q=nova"', $html);
        $this->assertStringNotContainsString('mastodon.example/@nova', $html);
    }
}
