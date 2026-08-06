<?php

namespace Tests\Feature\Admin;

use App\Application\Queries\SuggestedLocalActorsQuery;
use App\Application\Services\StaffUserManager;
use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class UserModerationVisibilityTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_suspend_syncs_actor_and_hides_from_suggestions(): void
    {
        $mod = $this->createFullAccount('modvis');
        $mod->forceFill(['is_moderator' => true])->save();
        $alice = $this->createFullAccount('alicevis');
        $bob = $this->createFullAccount('bobvis');

        app(StaffUserManager::class)->suspend($mod, $bob);

        $this->assertSame(User::STATUS_SUSPENDED, $bob->fresh()->status);
        $this->assertSame(Actor::STATUS_SUSPENDED, $bob->actor->fresh()->status);

        $suggestions = app(SuggestedLocalActorsQuery::class)
            ->forViewer($alice->actor)
            ->pluck('id');

        $this->assertNotContains($bob->actor->id, $suggestions);
    }

    public function test_disabled_user_profile_is_not_found(): void
    {
        $mod = $this->createFullAccount('moddisablevis');
        $mod->forceFill(['is_moderator' => true])->save();
        $target = $this->createFullAccount('disabledvis');

        app(StaffUserManager::class)->disable($mod, $target);

        $this->assertSame(Actor::STATUS_BLOCKED, $target->actor->fresh()->status);

        $this->get(route('profile.show', $target->username))->assertNotFound();
    }

    public function test_suspended_user_profile_is_obscured(): void
    {
        $mod = $this->createFullAccount('modsuspvis');
        $mod->forceFill(['is_moderator' => true])->save();
        $target = $this->createFullAccount('suspendedvis');

        app(StaffUserManager::class)->suspend($mod, $target);

        $this->get(route('profile.show', $target->username))
            ->assertOk()
            ->assertSee(__('openbook.profile.suspended_notice'))
            ->assertDontSee(__('openbook.profile.no_posts_yet'));
    }

    public function test_disabled_user_is_excluded_from_local_search(): void
    {
        $mod = $this->createFullAccount('modsearchvis');
        $mod->forceFill(['is_moderator' => true])->save();
        $target = $this->createFullAccount('hiddensearch');
        $target->profile->forceFill(['display_name' => 'Utente Segreto XYZ'])->save();

        app(StaffUserManager::class)->disable($mod, $target);

        $viewer = $this->createFullAccount('searchviewer');

        $this->actingAs($viewer)
            ->get(route('search.create', ['q' => 'Segreto XYZ']))
            ->assertOk()
            ->assertDontSee('Utente Segreto XYZ');
    }
}
