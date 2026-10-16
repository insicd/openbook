<?php

namespace Tests\Feature\Admin;

use App\Application\Services\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminAppearanceTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_admin_can_open_appearance_editor_and_preview(): void
    {
        $admin = $this->createFullAccount('adminaspetto');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->get(route('admin.appearance.edit'))
            ->assertOk()
            ->assertSee('name="custom_css"', false)
            ->assertSee('name="guest_home_feed"', false)
            ->assertSee(__('openbook.admin.appearance.guest_home_feed'), false)
            ->assertSee(route('admin.appearance.preview'), false);

        $this->actingAs($admin)
            ->get(route('admin.appearance.preview'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertSee(__('openbook.admin.appearance.preview_banner'))
            ->assertSee(__('openbook.admin.appearance.sample_title'))
            ->assertSee('id="ob-custom-css"', false);
    }

    public function test_admin_can_save_custom_css_which_applies_only_to_the_public_site(): void
    {
        $admin = $this->createFullAccount('admincsssave');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.appearance.update'), [
                'custom_css' => '.ob-brand { color: #c45c26; }',
            ])
            ->assertRedirect(route('admin.appearance.edit'));

        $this->assertSame(
            '.ob-brand { color: #c45c26; }',
            app(InstanceSettings::class)->customCss(),
        );

        $this->actingAs($admin)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee('id="ob-custom-css"', false)
            ->assertSee('.ob-brand { color: #c45c26; }', false);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('id="ob-custom-css"', false)
            ->assertDontSee('.ob-brand { color: #c45c26; }', false);
    }

    public function test_saved_css_strips_imports_and_style_breakout(): void
    {
        $admin = $this->createFullAccount('admincsssafe');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.appearance.update'), [
                'custom_css' => "@import url('https://evil.test/x.css');\n.ob-card { color: red; }\n</style><script>alert(1)</script>",
            ])
            ->assertRedirect(route('admin.appearance.edit'));

        $css = app(InstanceSettings::class)->customCss();

        $this->assertStringContainsString('.ob-card { color: red; }', $css);
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('</style', $css);

        $this->actingAs($admin)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertDontSee('</style><script>', false);
    }

    public function test_clearing_custom_css_removes_the_public_style_tag(): void
    {
        app(InstanceSettings::class)->updateCustomCss('.ob-brand { color: red; }');

        $admin = $this->createFullAccount('admincssclear');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.appearance.update'), [
                'custom_css' => '',
            ])
            ->assertRedirect(route('admin.appearance.edit'));

        $this->assertSame('', app(InstanceSettings::class)->customCss());

        $this->actingAs($admin)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertDontSee('id="ob-custom-css"', false);
    }

    public function test_admin_can_enable_the_guest_home_feed_from_appearance(): void
    {
        $admin = $this->createFullAccount('adminguesthome');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->assertFalse(app(InstanceSettings::class)->guestHomeFeed());

        $this->actingAs($admin)
            ->put(route('admin.appearance.update'), [
                'custom_css' => '',
                'guest_home_feed' => '1',
            ])
            ->assertRedirect(route('admin.appearance.edit'));

        $this->assertTrue(app(InstanceSettings::class)->guestHomeFeed());

        auth()->logout();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('ob-guest-home-layout', false)
            ->assertSee(__('openbook.home.feed_title', ['name' => config('app.name')]));

        $this->actingAs($admin)
            ->put(route('admin.appearance.update'), [
                'custom_css' => '',
                'guest_home_feed' => '0',
            ])
            ->assertRedirect(route('admin.appearance.edit'));

        $this->assertFalse(app(InstanceSettings::class)->guestHomeFeed());

        auth()->logout();

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('ob-guest-home-layout', false);
    }

    public function test_saving_custom_css_without_the_feed_field_leaves_the_guest_home_feed_unchanged(): void
    {
        app(InstanceSettings::class)->updateGuestHomeFeed(true);

        $admin = $this->createFullAccount('admincsskeepfeed');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.appearance.update'), [
                'custom_css' => '.ob-brand { color: #111111; }',
            ])
            ->assertRedirect(route('admin.appearance.edit'));

        $this->assertTrue(app(InstanceSettings::class)->guestHomeFeed());
    }

    public function test_moderators_cannot_update_appearance(): void
    {
        $mod = $this->createFullAccount('modaspetto');
        $mod->forceFill(['is_moderator' => true])->save();

        $this->actingAs($mod)
            ->get(route('admin.appearance.edit'))
            ->assertForbidden();

        $this->actingAs($mod)
            ->put(route('admin.appearance.update'), [
                'custom_css' => 'body { display: none; }',
            ])
            ->assertForbidden();
    }
}
