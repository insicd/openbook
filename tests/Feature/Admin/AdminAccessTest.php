<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_guests_cannot_access_the_admin_panel(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_regular_users_cannot_access_the_admin_panel(): void
    {
        $user = $this->createFullAccount('utentenormale');

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.reports.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.appearance.edit'))->assertForbidden();
    }

    public function test_moderators_can_access_moderation_but_not_settings(): void
    {
        $mod = $this->createFullAccount('modpanel');
        $mod->forceFill(['is_moderator' => true])->save();

        $this->actingAs($mod)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($mod)->get(route('admin.reports.index'))->assertOk();
        $this->actingAs($mod)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($mod)->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($mod)->get(route('admin.appearance.edit'))->assertForbidden();
        foreach (['admin.video.edit', 'admin.moderation.edit'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->put(route('admin.video.update'), [])->assertForbidden();
        $this->put(route('admin.moderation.update'), [])->assertForbidden();
    }

    public function test_admins_can_access_the_full_panel(): void
    {
        $admin = $this->createFullAccount('adminpanel');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//div[@class="ob-admin-shell"]/aside[@class="ob-admin-nav"]')->length);
        $this->assertSame(1, $xpath->query('//div[@class="ob-admin-shell"]/main[@id="ob-admin-content"]')->length);
        $this->assertSame(3, $xpath->query('//aside//hr')->length);
        $expected = ['admin.dashboard', 'admin.settings.edit', 'admin.appearance.edit', 'admin.users.index', 'admin.moderation.edit', 'admin.reports.index', 'admin.video.edit', 'admin.object_storage.edit', 'admin.database.index', 'admin.queue.index', 'admin.audit.index', 'admin.updates.show', 'admin.relays.index'];
        $actual = [];
        foreach ($xpath->query('//aside//a') as $link) {
            $actual[] = $link->getAttribute('href');
        }
        $this->assertSame(array_map(fn ($name) => route($name), $expected), $actual);

        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk();
        $this->actingAs($admin)->get(route('admin.appearance.edit'))->assertOk();
        foreach (['general' => 'site_name', 'registration' => 'instance_rules', 'content' => 'post_max_length'] as $tab => $field) {
            $this->get(route('admin.settings.edit', ['tab' => $tab]))->assertOk()
                ->assertSee('name="'.$field.'"', false)->assertSee('aria-current="page"', false)
                ->assertDontSee('name="video_enabled"', false)->assertDontSee('name="world_hide_content_warnings"', false);
        }
        $this->get(route('admin.video.edit'))->assertOk()->assertSee('name="video_enabled"', false)->assertDontSee('name="site_name"', false);
        $this->get(route('admin.moderation.edit'))->assertOk()->assertSee('name="world_hide_content_warnings"', false)->assertDontSee('name="video_enabled"', false);

        $this->actingAs($admin)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee(__('openbook.nav.admin'), false);
    }

    public function test_regular_users_do_not_see_the_admin_sidebar_link(): void
    {
        $user = $this->createFullAccount('senzaadmin');

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertDontSee(__('openbook.nav.admin'), false);
    }
}
