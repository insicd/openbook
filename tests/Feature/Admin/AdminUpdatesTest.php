<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminUpdatesTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_admin_can_view_updates_page_when_manifest_is_reachable(): void
    {
        Http::fake([
            'https://about.openb.app/releases/latest.json' => Http::response([
                'schema_version' => 1,
                'version' => '9.9.9',
                'min_php' => '8.2.0',
                'download_url' => 'https://about.openb.app/releases/openbook-9.9.9.zip',
                'sha256' => str_repeat('cd', 32),
                'notes' => 'Test release',
                'requires_migration' => true,
            ], 200),
        ]);

        $admin = $this->createFullAccount('updateadmin');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->get(route('admin.updates.show'))
            ->assertOk()
            ->assertSee('9.9.9', false)
            ->assertSee(__('openbook.admin.updates.apply_button', ['version' => '9.9.9']), false);
    }

    public function test_moderators_cannot_access_updates(): void
    {
        $mod = $this->createFullAccount('updatemod');
        $mod->forceFill(['is_moderator' => true])->save();

        $this->actingAs($mod)
            ->get(route('admin.updates.show'))
            ->assertForbidden();
    }

    public function test_apply_requires_confirmation(): void
    {
        Http::fake([
            'https://about.openb.app/releases/latest.json' => Http::response([
                'schema_version' => 1,
                'version' => '9.9.9',
                'min_php' => '8.2.0',
                'download_url' => 'https://about.openb.app/releases/openbook-9.9.9.zip',
                'sha256' => str_repeat('cd', 32),
                'requires_migration' => true,
            ], 200),
        ]);

        $admin = $this->createFullAccount('updateconfirm');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->post(route('admin.updates.apply'), [])
            ->assertSessionHasErrors('confirm');
    }
}
