<?php

namespace Tests\Feature;

use App\Application\Services\CommunityRegistrar;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ComposeModalTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_non_home_pages_include_the_compose_modal_and_visible_plus_button(): void
    {
        $user = $this->createFullAccount('modaluser');

        $response = $this->actingAs($user)->get(route('settings.edit'));

        $response->assertOk();
        $response->assertSee('id="ob-compose-modal"', false);
        $response->assertSee('id="ob-modal-composer"', false);
        $response->assertSee('id="modal-composer-body"', false);
        $response->assertSee('data-compose-home="0"', false);
        $response->assertSee('ob-compose-btn--header is-visible', false);
        $response->assertSee(__('openbook.nav.new_post_dialog'));
    }

    public function test_home_feed_keeps_inline_composer_and_marks_plus_as_home_mode(): void
    {
        $user = $this->createFullAccount('homecompose');

        $response = $this->actingAs($user)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('id="ob-composer"', false);
        $response->assertSee('id="composer-body"', false);
        $response->assertSee('data-compose-home="1"', false);
        $response->assertSee('id="ob-compose-modal"', false);
        $response->assertDontSee('ob-compose-btn--header is-visible', false);
    }

    public function test_publishing_from_the_modal_composer_redirects_to_the_post(): void
    {
        $user = $this->createFullAccount('modalpost');

        $response = $this->actingAs($user)->from(route('settings.edit'))->post(route('posts.store'), [
            'body' => 'Post dal dialog della navbar.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'composer_ui' => 'modal',
        ]);

        $post = Post::query()->where('actor_id', $user->actor->id)->first();
        $this->assertNotNull($post);
        $response->assertRedirect(route('posts.show', $post));
    }

    public function test_validation_errors_from_modal_reopen_it_on_the_previous_page(): void
    {
        $user = $this->createFullAccount('modalerr');

        $response = $this->actingAs($user)->from(route('settings.edit'))->post(route('posts.store'), [
            'body' => '',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'composer_ui' => 'modal',
        ]);

        $response->assertRedirect(route('settings.edit'));
        $response->assertSessionHasErrors('body');

        $this->actingAs($user)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('data-open-on-load="1"', false);
    }

    public function test_modal_composer_lists_joined_communities_like_the_home_composer(): void
    {
        $user = $this->createFullAccount('modalcomm');
        app(CommunityRegistrar::class)->register($user, [
            'slug' => 'gruppo_modal',
            'name' => 'Gruppo modal',
        ]);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('modal_composer-community', false)
            ->assertSee('Gruppo modal');
    }
}
