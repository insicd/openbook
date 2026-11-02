<?php

namespace Tests\Feature\Media;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\CommunityUpdater;
use App\Application\Services\ProfileUpdater;
use App\Federation\Serialization\ActorSerializer;
use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Media\AssetStorage;
use App\Infrastructure\Media\InstanceIconUploader;
use App\Infrastructure\Media\MediaStorage;
use App\Infrastructure\Media\ProfileImageUploader;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class AssetStorageTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(MediaStorage::OBJECT_DISK);
        Queue::fake();
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('portrait.jpg', 1800, 1200);
    }

    public function test_profile_images_store_locations_and_work_in_both_delivery_modes(): void
    {
        $user = $this->createFullAccount('assetprofile');
        SystemSetting::putBool('object_storage_enabled', true);
        app(ProfileUpdater::class)->update($user, ['display_name' => 'Portrait'], $this->image(), $this->image());
        $profile = $user->fresh()->profile;
        $this->assertSame(MediaStorage::OBJECT_DISK, $profile->avatar_disk);
        $this->assertSame(MediaStorage::OBJECT_DISK, $profile->cover_disk);
        $this->assertDatabaseCount('media', 0);
        Storage::disk('public')->assertDirectoryEmpty('/');
        $bytes = Storage::disk(MediaStorage::OBJECT_DISK)->get($profile->avatar_path);
        $this->assertSame(512, getimagesizefromstring($bytes)[0]);
        $this->get($profile->avatarUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertStreamedContent($bytes);
        $this->head($profile->coverUrl())->assertOk()->assertContent('');
        $proxy = $profile->avatarUrl();
        SystemSetting::putBool('object_storage_direct', true);
        SystemSetting::put('object_storage_public_url', 'https://media.example/');
        $this->assertSame('https://media.example/'.$profile->avatar_path, $profile->avatarUrl());
        $actor = $user->fresh()->actor;
        $this->assertSame($profile->avatarUrl(), $actor->avatarUrl());
        $this->assertSame($profile->avatarUrl(), ActorSerializer::serialize($actor)['icon']['url']);
        $this->get($proxy)->assertOk();
    }

    public function test_replacement_uses_previous_disk_and_old_proxy_cannot_serve_new_version(): void
    {
        $user = $this->createFullAccount('assetreplace');
        $updater = app(ProfileUpdater::class);
        $updater->update($user, ['display_name' => 'Local'], $this->image(), null);
        $oldPath = $user->profile->avatar_path;
        SystemSetting::putBool('object_storage_enabled', true);
        $updater->update($user, ['display_name' => 'Bucket'], $this->image(), null);
        Storage::disk('public')->assertMissing($oldPath);
        $proxy = $user->profile->avatarUrl();
        $cloudPath = $user->profile->avatar_path;
        SystemSetting::putBool('object_storage_enabled', false);
        $this->get($proxy)->assertOk();
        $updater->update($user, ['display_name' => 'Local again'], $this->image(), null);
        $this->assertSame('public', $user->profile->avatar_disk);
        Storage::disk(MediaStorage::OBJECT_DISK)->assertMissing($cloudPath);
        $this->get($proxy)->assertNotFound();
        Storage::disk('public')->assertExists($user->profile->avatar_path);
    }

    public function test_failed_profile_update_rolls_back_new_asset_and_keeps_previous_files(): void
    {
        $user = $this->createFullAccount('assetrollback');
        $updater = app(ProfileUpdater::class);
        $updater->update($user, ['display_name' => 'Original'], $this->image(), null);
        $old = $user->profile->avatar_path;
        SystemSetting::putBool('object_storage_enabled', true);
        try {
            $updater->update($user, ['display_name' => 'Failed'], $this->image(), UploadedFile::fake()->create('script.php', 2, 'text/x-php'));
            $this->fail('Expected invalid cover');
        } catch (\InvalidArgumentException) {
            $this->assertSame($old, $user->fresh()->profile->avatar_path);
            $this->assertSame('Original', $user->fresh()->profile->display_name);
            Storage::disk('public')->assertExists($old);
            Storage::disk(MediaStorage::OBJECT_DISK)->assertDirectoryEmpty('/');
        }
    }

    public function test_database_failure_keeps_previous_avatar_and_cleans_new_upload(): void
    {
        $user = $this->createFullAccount('assetdbrollback');
        $updater = app(ProfileUpdater::class);
        $updater->update($user, ['display_name' => 'Original'], $this->image(), null);
        $old = $user->profile->avatar_path;
        SystemSetting::putBool('object_storage_enabled', true);
        try {
            DB::transaction(function () use ($user, $updater): void {
                $updater->update($user, ['display_name' => 'Rollback'], $this->image(), null);
                throw new \RuntimeException('Outer transaction failed');
            });
        } catch (\RuntimeException) {
            $this->assertSame($old, $user->fresh()->profile->avatar_path);
            Storage::disk('public')->assertExists($old);
            Storage::disk(MediaStorage::OBJECT_DISK)->assertDirectoryEmpty('/');
        }
    }

    public function test_community_uses_managed_paths_and_dynamic_actor_urls(): void
    {
        $owner = $this->createFullAccount('assetcommunity');
        $community = app(CommunityRegistrar::class)->register($owner, ['slug' => 'asset-club', 'name' => 'Asset club']);
        SystemSetting::putBool('object_storage_enabled', true);
        app(CommunityUpdater::class)->update($community, ['name' => 'Photos'], $this->image(), $this->image());
        $actor = $community->actor->fresh();
        $this->assertSame(MediaStorage::OBJECT_DISK, $community->fresh()->avatar_disk);
        $this->assertStringContainsString('/asset-files/community/', $actor->avatarUrl());
        $this->assertNull($actor->icon_url);
        $this->get($actor->avatarUrl())->assertOk();
        $this->get(app(AssetStorage::class)->url(MediaStorage::OBJECT_DISK, $community->fresh()->avatar_path, 'actor', $actor->id, 'icon'))->assertOk();
        $this->get($actor->coverUrl())->assertOk();
        SystemSetting::putBool('object_storage_direct', true);
        SystemSetting::put('object_storage_public_url', 'https://cdn.example/');
        $this->assertSame('https://cdn.example/'.$community->fresh()->avatar_path, $actor->avatarUrl());
        $this->assertSame($actor->avatarUrl(), ActorSerializer::serialize($actor)['icon']['url']);
        $this->assertSame($actor->coverUrl(), ActorSerializer::serialize($actor)['image']['url']);
        $old = $community->fresh()->avatar_path;
        SystemSetting::putBool('object_storage_enabled', false);
        app(CommunityUpdater::class)->update($community, ['name' => 'Local'], $this->image(), null);
        Storage::disk(MediaStorage::OBJECT_DISK)->assertMissing($old);
        $this->assertSame('public', $community->fresh()->avatar_disk);
    }

    public function test_legacy_community_local_image_is_replaced_but_remote_lookalike_is_untouched(): void
    {
        $owner = $this->createFullAccount('assetlegacy');
        $community = app(CommunityRegistrar::class)->register($owner, ['slug' => 'legacy-club', 'name' => 'Legacy']);
        $uploader = app(ProfileImageUploader::class);
        $local = $uploader->storeAvatar($this->image(), null);
        $community->actor->update(['icon_url' => Storage::disk('public')->url($local)]);
        SystemSetting::putBool('object_storage_enabled', true);
        app(CommunityUpdater::class)->update($community, ['name' => 'Bucket'], $this->image(), null);
        Storage::disk('public')->assertMissing($local);
        Storage::disk('public')->put('avatars/remote.jpg', 'keep');
        $community->update(['avatar_path' => null]);
        $community->actor->update(['icon_url' => 'https://remote.example/storage/avatars/remote.jpg']);
        app(CommunityUpdater::class)->update($community, ['name' => 'New'], $this->image(), null);
        Storage::disk('public')->assertExists('avatars/remote.jpg');
    }

    public function test_community_images_are_consistent_across_html_actor_rest_and_activitypub_access(): void
    {
        Http::preventStrayRequests();
        $owner = $this->createFullAccount('communityimages');
        $community = app(CommunityRegistrar::class)->register($owner, ['slug' => 'community_images', 'name' => 'Community images']);
        $oldAvatar = 'https://old.example/avatar.jpg';
        $oldCover = 'https://old.example/cover.jpg';
        $community->actor->update(['icon_url' => $oldAvatar, 'image_url' => $oldCover]);
        $this->configurePassportKeys();
        Passport::actingAs($owner, ['api'], 'rest');
        $this->actingAs($owner);

        foreach (['legacy', 'newCover', 'newAvatar', 'direct'] as $phase) {
            if ($phase === 'newCover') {
                SystemSetting::putBool('object_storage_enabled', true);
                app(CommunityUpdater::class)->update($community, ['name' => 'Community images'], null, $this->image());
                $this->assertNotNull($community->fresh()->cover_path);
                $this->assertNull($community->actor->fresh()->image_url);
                $this->assertNull($community->fresh()->avatar_path);
                $this->assertSame($oldAvatar, $community->actor->fresh()->icon_url);
                // A stale value must not override the new managed cover.
                $community->actor->update(['image_url' => $oldCover]);
            } elseif ($phase === 'newAvatar') {
                app(CommunityUpdater::class)->update($community, ['name' => 'Community images'], $this->image(), null);
                $this->assertNull($community->actor->fresh()->icon_url);
                $community->actor->update(['icon_url' => $oldAvatar]);
            } elseif ($phase === 'direct') {
                SystemSetting::putBool('object_storage_direct', true);
                SystemSetting::put('object_storage_public_url', 'https://cdn.example/');
            }
            $current = $community->fresh();
            $avatar = $current->avatarUrl();
            $cover = $current->coverUrl();
            $html = $this->get(route('communities.show', $current))->assertOk()
                ->assertSee($avatar, false)->assertSee($cover, false);
            if ($phase !== 'legacy') {
                $html->assertDontSee($oldCover, false);
            }
            if (in_array($phase, ['newAvatar', 'direct'], true)) {
                $html->assertDontSee($oldAvatar, false);
            }
            $this->get(route('actors.show', $current->actor))->assertRedirect(route('profile.show', $current->slug));
            $this->get(route('profile.show', $current->slug))->assertRedirect(route('communities.show', $current));
            $this->get(route('profile.redirect', $current->slug))->assertRedirect(route('communities.show', $current));
            $this->get(route('profile.redirect', $current->slug), ['Accept' => 'application/activity+json'])->assertOk()
                ->assertJsonPath('type', 'Group')
                ->assertJsonPath('icon.url', $avatar)->assertJsonPath('image.url', $cover);
            $this->get('/rest/v1/persons/'.$current->actor_id, ['Authorization' => 'Bearer test-token', 'Accept' => 'application/json'])->assertOk()
                ->assertJsonPath('personType', 'group')
                ->assertJsonPath('personProfile.personAvatar', $avatar)
                ->assertJsonPath('personProfile.personCoverImage', $cover);
        }
        Http::assertNothingSent();
    }

    public function test_asset_route_cannot_choose_arbitrary_files_remote_actors_or_wrong_versions(): void
    {
        $user = $this->createFullAccount('assetlimits');
        SystemSetting::putBool('object_storage_enabled', true);
        app(ProfileUpdater::class)->update($user, ['display_name' => 'Avatar'], $this->image(), null);
        $url = $user->profile->avatarUrl();
        $this->get(str_replace(sha1($user->profile->avatar_path), str_repeat('0', 40), $url))->assertNotFound();
        $this->get(str_replace('/avatar/', '/password/', $url))->assertNotFound();
        $this->get(route('assets.files.show', ['kind' => 'instance', 'id' => 'site', 'slot' => '../.env', 'version' => str_repeat('a', 40)]))->assertNotFound();
        $actor = $user->actor;
        $actor->forceFill(['is_local' => false, 'icon_url' => 'https://remote.example/avatar.jpg'])->save();
        $this->assertSame('https://remote.example/avatar.jpg', $actor->avatarUrl());
        $this->get(app(AssetStorage::class)->url(MediaStorage::OBJECT_DISK, $user->profile->avatar_path, 'actor', $actor->id, 'icon'))->assertNotFound();
    }

    public function test_failed_icon_batch_cleans_partial_objects_without_removing_old_icons(): void
    {
        $uploader = app(InstanceIconUploader::class);
        $old = $uploader->store(UploadedFile::fake()->image('old.png', 512, 512), null);
        SystemSetting::putBool('object_storage_enabled', true);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $writes = 0;
        $disk->shouldReceive('put')->twice()->andReturnUsing(function () use (&$writes) {
            if (++$writes === 2) {
                throw new \RuntimeException('SECRET signed request');
            }

            return true;
        });
        $disk->shouldReceive('delete')->once()->with(\Mockery::on(fn (array $paths): bool => count($paths) === 6))->andReturnTrue();
        Storage::set(MediaStorage::OBJECT_DISK, $disk);
        try {
            $uploader->store(UploadedFile::fake()->image('new.png', 512, 512), $old);
            $this->fail('Expected failed object write');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
            Storage::disk('public')->assertExists($old.'/favicon-32.png');
        }
    }

    public function test_community_failure_keeps_previous_references_and_files(): void
    {
        $owner = $this->createFullAccount('communityrollback');
        $community = app(CommunityRegistrar::class)->register($owner, ['slug' => 'rollback-club', 'name' => 'Original']);
        $updater = app(CommunityUpdater::class);
        $updater->update($community, ['name' => 'Original'], $this->image(), null);
        $old = $community->avatar_path;
        SystemSetting::putBool('object_storage_enabled', true);
        try {
            $updater->update($community, ['name' => 'Failed'], $this->image(), UploadedFile::fake()->create('invalid.txt', 2, 'text/plain'));
            $this->fail('Expected invalid cover');
        } catch (\InvalidArgumentException) {
            $this->assertSame($old, $community->fresh()->avatar_path);
            $this->assertSame('Original', $community->actor->fresh()->name);
            Storage::disk('public')->assertExists($old);
            Storage::disk(MediaStorage::OBJECT_DISK)->assertDirectoryEmpty('/');
        }
    }

    public function test_community_migration_moves_legacy_and_bucket_references_and_preserves_external_urls(): void
    {
        $owner = $this->createFullAccount('communitymigration');
        $registrar = app(CommunityRegistrar::class);
        $local = $registrar->register($owner, ['slug' => 'legacy-image', 'name' => 'Local']);
        $bucket = $registrar->register($owner, ['slug' => 'bucket-image', 'name' => 'Bucket']);
        $external = $registrar->register($owner, ['slug' => 'external-image', 'name' => 'External']);
        $migration = require database_path('migrations/2026_11_02_000001_move_community_asset_locations.php');
        $migration->down();
        Storage::disk('public')->put('avatars/legacy.jpg', 'local bytes');
        Storage::disk(MediaStorage::OBJECT_DISK)->put('covers/cloud.jpg', 'bucket bytes');
        $localUrl = Storage::disk('public')->url('avatars/legacy.jpg');
        $externalUrl = 'https://remote.example/storage/avatars/keep.jpg';
        DB::table('actors')->where('id', $local->actor_id)->update(['icon_url' => $localUrl]);
        DB::table('actors')->where('id', $bucket->actor_id)->update([
            'image_path' => 'covers/cloud.jpg', 'image_disk' => MediaStorage::OBJECT_DISK,
        ]);
        DB::table('actors')->where('id', $external->actor_id)->update(['icon_url' => $externalUrl]);
        $migration->up();
        $this->assertSame('avatars/legacy.jpg', $local->fresh()->avatar_path);
        $this->assertSame('public', $local->fresh()->avatar_disk);
        $this->assertSame($localUrl, $local->fresh()->actor->avatarUrl());
        $this->assertNull($local->actor->fresh()->icon_url);
        $this->assertSame('covers/cloud.jpg', $bucket->fresh()->cover_path);
        $this->assertSame(MediaStorage::OBJECT_DISK, $bucket->fresh()->cover_disk);
        $this->assertSame($externalUrl, $external->fresh()->actor->avatarUrl());
        $this->assertNull($external->fresh()->avatar_path);
        $this->assertFalse(Schema::hasColumn('actors', 'icon_disk'));
        Storage::disk('public')->assertExists('avatars/legacy.jpg');
        Storage::disk(MediaStorage::OBJECT_DISK)->assertExists('covers/cloud.jpg');
        $migration->down();
        $this->assertSame($localUrl, DB::table('actors')->where('id', $local->actor_id)->value('icon_url'));
        $this->assertSame('covers/cloud.jpg', DB::table('actors')->where('id', $bucket->actor_id)->value('image_path'));
        $migration->up();
        $this->assertSame('covers/cloud.jpg', $bucket->fresh()->cover_path);
    }

    public function test_migration_round_trip_preserves_legacy_paths_and_local_defaults(): void
    {
        $user = $this->createFullAccount('assetmigration');
        $user->profile->update(['avatar_path' => 'avatars/legacy.jpg']);
        $migration = require database_path('migrations/2026_11_02_000000_add_asset_storage_locations.php');
        $relocation = require database_path('migrations/2026_11_02_000001_move_community_asset_locations.php');
        $relocation->down();
        $migration->down();
        $migration->up();
        $relocation->up();
        $profile = $user->profile->fresh();
        $this->assertSame('avatars/legacy.jpg', $profile->avatar_path);
        $this->assertSame('public', $profile->avatar_disk);
        $this->assertSame('public', $profile->cover_disk);
        $this->assertFalse(Schema::hasColumn('actors', 'icon_path'));
        $this->assertTrue(Schema::hasColumn('communities', 'avatar_path'));
    }
}
