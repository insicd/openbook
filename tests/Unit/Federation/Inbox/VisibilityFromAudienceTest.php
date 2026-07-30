<?php

namespace Tests\Unit\Federation\Inbox;

use App\Domain\Posts\Post;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Serialization\NoteSerializer;
use Tests\TestCase;

class VisibilityFromAudienceTest extends TestCase
{
    public function test_it_treats_a_string_public_to_as_public(): void
    {
        $visibility = app(RemoteNoteUpserter::class)->visibilityFromAudience([
            'to' => NoteSerializer::PUBLIC_STREAM,
            'cc' => 'https://remoto.example/users/a/followers',
        ]);

        $this->assertSame(Post::VISIBILITY_PUBLIC, $visibility);
    }

    public function test_it_treats_a_string_public_cc_as_unlisted(): void
    {
        $visibility = app(RemoteNoteUpserter::class)->visibilityFromAudience([
            'to' => 'https://remoto.example/users/a/followers',
            'cc' => NoteSerializer::PUBLIC_STREAM,
        ]);

        $this->assertSame(Post::VISIBILITY_UNLISTED, $visibility);
    }

    public function test_it_accepts_as_public_aliases(): void
    {
        $upserter = app(RemoteNoteUpserter::class);

        $this->assertSame(Post::VISIBILITY_PUBLIC, $upserter->visibilityFromAudience([
            'to' => ['as:Public'],
        ]));

        $this->assertSame(Post::VISIBILITY_PUBLIC, $upserter->visibilityFromAudience([
            'to' => ['Public'],
        ]));
    }
}
