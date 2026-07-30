<?php

namespace Tests\Feature\Admin;

use App\Application\Services\DomainBlockManager;
use App\Domain\Moderation\DomainBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminDomainBlockTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_admin_can_block_and_unblock_a_domain(): void
    {
        $admin = $this->createFullAccount('admindomain');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->post(route('admin.domain_blocks.store'), [
                'domain' => 'https://Spam.Example/path',
                'reason' => 'spam',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('domain_blocks', [
            'domain' => 'spam.example',
            'reason' => 'spam',
        ]);

        $this->assertTrue(app(DomainBlockManager::class)->isBlockedHost('spam.example'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.block']);

        $block = DomainBlock::query()->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.domain_blocks.destroy', $block))
            ->assertRedirect();

        $this->assertDatabaseCount('domain_blocks', 0);
    }

    public function test_moderators_cannot_manage_domain_blocks(): void
    {
        $mod = $this->createFullAccount('moddomain');
        $mod->forceFill(['is_moderator' => true])->save();

        $this->actingAs($mod)
            ->get(route('admin.domain_blocks.index'))
            ->assertForbidden();
    }
}
