<?php

namespace Tests\Concerns;

use App\Domain\Polls\Poll;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\RemoteNoteUpserter;
use Illuminate\Support\Facades\Http;

trait CreatesRemotePolls
{
    use CreatesAccounts, CreatesRemoteActors;

    /** @return array{Actor, Poll} */
    private function votingFixture(bool $multiple = false): array
    {
        Http::preventStrayRequests();
        $voter = $this->createFullAccount('pollvoter')->actor;
        $author = $this->createRemoteActor('alice', 'remote.example');
        $name = $multiple ? 'multiple-update' : 'single-update';
        $note = json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Polls/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR)['object'];
        $note['endTime'] = now()->addDay()->toIso8601String();
        $post = app(RemoteNoteUpserter::class)->upsertPost($note, $note['id'], $author, 'Poll', now(), notifyMentions: false);

        return [$voter, $post->poll->load('options')];
    }
}
