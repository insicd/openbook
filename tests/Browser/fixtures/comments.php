<?php

use App\Application\Services\CommentComposer;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\CreatesAccounts;

putenv('APP_CONFIG_CACHE='.sys_get_temp_dir().'/openbook-comments-browser-config.php');
putenv('APP_ROUTES_CACHE='.sys_get_temp_dir().'/openbook-comments-browser-routes.php');
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:', 'openbook.actor_key_bits' => 2048]);
Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
$app->setLocale('it');
URL::forceRootUrl('http://openbook.test');
URL::forceScheme('http');
request()->setLaravelSession(app('session.store'));
view()->share('errors', new ViewErrorBag);
Queue::fake();
$fixture = new class
{
    use CreatesAccounts;

    public function render(): array
    {
        $user = $this->createFullAccount('commenteditor');
        Auth::setUser($user);
        request()->setUserResolver(fn () => $user);
        $post = Post::create(['actor_id' => $user->actor->id, 'body' => 'Un post per discutere insieme.',
            'visibility' => 'public', 'published_at' => now()]);
        $comment = app(CommentComposer::class)->compose($user->actor, $post, 'Il testo del mio commento da modificare.');
        $post->load(Post::CARD_RELATIONS);
        Post::annotateViewerState([$post], $user->actor);
        $comment->load('actor.user.profile', 'media', 'parent');
        Comment::annotateViewerState([$comment], $user->actor);

        return ['edit' => view('comments.edit', compact('comment'))->render(),
            'thread' => view('comments.show', ['comment' => $comment, 'post' => $post,
                'ancestors' => [], 'focusedNode' => ['comment' => $comment, 'children' => []]])->render()];
    }
};
echo json_encode($fixture->render(), JSON_THROW_ON_ERROR);
