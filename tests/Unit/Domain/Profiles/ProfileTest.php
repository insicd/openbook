<?php

namespace Tests\Unit\Domain\Profiles;

use App\Domain\Profiles\Profile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    public function test_avatar_url_is_built_from_the_public_disk_configuration(): void
    {
        $profile = new Profile(['avatar_path' => 'avatars/example.jpg']);

        $this->assertSame(
            Storage::disk('public')->url('avatars/example.jpg'),
            $profile->avatarUrl(),
        );
    }

    public function test_cover_url_is_built_from_the_public_disk_configuration(): void
    {
        $profile = new Profile(['cover_path' => 'covers/example.jpg']);

        $this->assertSame(
            Storage::disk('public')->url('covers/example.jpg'),
            $profile->coverUrl(),
        );
    }

    public function test_avatar_and_cover_url_are_null_without_a_stored_file(): void
    {
        $profile = new Profile;

        $this->assertNull($profile->avatarUrl());
        $this->assertNull($profile->coverUrl());
    }
}
