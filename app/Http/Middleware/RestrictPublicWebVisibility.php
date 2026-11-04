<?php

namespace App\Http\Middleware;

use App\Application\Services\PublicWebVisibility;
use App\Domain\Events\Event;
use App\Domain\Posts\Post;
use App\Http\Support\ActivityPubNegotiation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RestrictPublicWebVisibility
{
    private const PAGES = [
        'home', 'posts.show', 'posts.likes', 'posts.announces', 'comments.show',
        'events.index', 'events.archive', 'events.show', 'event-comments.show',
        'profile.show', 'profile.redirect', 'profile.photos', 'profile.events', 'profile.followers', 'profile.following',
        'communities.index', 'communities.show', 'communities.members', 'hashtags.index', 'hashtags.show',
    ];

    private const ACTIVITYPUB = ['posts.show', 'comments.show', 'events.show', 'event-comments.show', 'profile.show', 'profile.redirect', 'communities.show'];

    public function __construct(private readonly PublicWebVisibility $visibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $visibility = $this->visibility;
        $name = $request->route()?->getName();
        if (! in_array($name, self::PAGES, true)) {
            return $next($request);
        }
        if (in_array($name, self::ACTIVITYPUB, true) && ActivityPubNegotiation::wantsActivityPub($request)) {
            $response = $next($request);
            $response->setVary('Accept', false);

            return $response;
        }
        $allowed = true;
        if (! $request->user() && $name !== 'home') {
            $allowed = $visibility->allowsGuests();
            if (in_array($name, ['hashtags.index', 'hashtags.show'], true)) {
                $allowed = $visibility->allowsHashtags();
            }
            $post = $request->route('post');
            $event = $request->route('event');
            if ($name === 'comments.show') {
                $post = $request->route('comment')?->post;
            } elseif ($name === 'event-comments.show') {
                $event = $request->route('comment')?->event;
            }
            if ($post instanceof Post && ! $visibility->allowsContent($post)) {
                abort_unless(Post::query()->whereKey($post->id)->visibleTo(null)->exists(), 404);
                $allowed = false;
            }
            if ($event instanceof Event && ! $visibility->allowsContent($event)) {
                abort_unless(Event::query()->whereKey($event->id)->visibleTo(null)->exists(), 404);
                $allowed = false;
            }
            if ($community = $request->route('community')) {
                $allowed = $allowed && $visibility->allowsActor($community->actor);
            }
        }
        if (! $allowed) {
            $response = $request->ajax() || $request->expectsJson()
                ? response()->json(['message' => __('openbook.public_visibility.login_required')], 401)
                : redirect()->guest(route('login'));
        } else {
            $response = $next($request);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary(['Accept', 'Cookie'], false);

        return $response;
    }
}
