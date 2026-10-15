<?php

use App\Domain\Accounts\User;
use App\Domain\Polls\Poll;
use App\Domain\Polls\PollOption;
use App\Domain\Polls\PollVote;
use App\Domain\Polls\PollVoteChoice;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ViewErrorBag;

putenv('APP_CONFIG_CACHE='.sys_get_temp_dir().'/openbook-browser-config.php');
putenv('APP_ROUTES_CACHE='.sys_get_temp_dir().'/openbook-browser-routes.php');
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
$app->setLocale('en');
URL::forceRootUrl('http://openbook.test');
Cache::put('openbook.domain_blocks', [], 60);
request()->setLaravelSession(app('session.store'));
view()->share('errors', new ViewErrorBag);
$user = (new User)->forceFill(['id' => '00000000-0000-4000-8000-000000000001', 'status' => User::STATUS_ACTIVE]);
$actor = (new Actor)->forceFill(['id' => '00000000-0000-4000-8000-000000000002', 'user_id' => $user->id, 'is_local' => true, 'status' => Actor::STATUS_ACTIVE, 'uri' => 'http://openbook.test/users/author']);
$user->setRelation('actor', $actor);
$actor->setRelation('user', $user);
auth()->setUser($user);
$post = (new Post)->forceFill(['id' => '00000000-0000-4000-8000-000000000003', 'actor_id' => $actor->id, 'visibility' => 'public', 'status' => Post::STATUS_PUBLISHED, 'published_at' => now()]);
$post->setRelation('actor', $actor);
$poll = (new Poll)->forceFill(['id' => '00000000-0000-4000-8000-000000000004', 'post_id' => $post->id, 'multiple' => true, 'voters_count' => 0, 'ends_at' => now()->addDay()]);
$poll->setRelation('post', $post)->setRelation('viewerVote', null)->setRelation('viewerCanSee', true);
$post->setRelation('poll', $poll);
$options = new Collection;
foreach (['Saturday', 'Sunday'] as $index => $name) {
    $options->push((new PollOption)->forceFill(['id' => '00000000-0000-4000-8000-00000000000'.($index + 5),
        'poll_id' => $poll->id, 'position' => $index, 'name' => $name, 'votes_count' => 0]));
}
$poll->setRelation('options', $options);
$initial = view('posts._poll', ['post' => $post])->render();
request()->query->set('poll_results', $post->id);
$results = view('posts._poll', ['post' => $post])->render();
request()->query->remove('poll_results');
$vote = new PollVote;
$vote->setRelation('choices', new Collection([(new PollVoteChoice)->forceFill(['name' => 'Saturday', 'position' => 0])]));
$poll->setRelation('viewerVote', $vote);
$poll->voters_count = 1;
$poll->options[0]->votes_count = 1;
$confirmed = view('posts._poll', ['post' => $post])->render();
$entries = new Collection;
for ($i = 0; $i < 25; $i++) {
    $participant = (new Actor)->forceFill(['id' => 'participant'.$i, 'is_local' => false, 'status' => 'active',
        'uri' => 'https://voters.example/users/participant'.$i, 'domain' => 'voters.example', 'preferred_username' => 'participant'.$i,
        'name' => 'Participant '.$i]);
    $participant->setRelation('user', null);
    $ballot = new PollVote;
    $ballot->setRelation('actor', $participant);
    $entry = new PollVoteChoice;
    $entries->push($entry->setRelation('vote', $ballot));
}
$votersHtml = [];
foreach ($options as $option) {
    $option->votes_count = 214;
}
foreach ($options as $selected) {
    $paginator = new LengthAwarePaginator($entries, 211, 25, 1, [
        'path' => route('posts.poll.voters', [$post, $selected->id]),
    ]);
    $votersHtml[$selected->id] = view('polls._voters', ['post' => $post, 'poll' => $poll, 'selected' => $selected,
        'voters' => $paginator, 'archivedCount' => 3])->render();
}
echo json_encode(['composer' => view('composer._poll', ['prefix' => 'browser', 'errors' => new ViewErrorBag])->render(),
    'poll' => $initial, 'results' => $results, 'confirmed' => $confirmed, 'voters' => $votersHtml], JSON_THROW_ON_ERROR);
