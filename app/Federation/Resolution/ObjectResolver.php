<?php

namespace App\Federation\Resolution;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;

/**
 * Risolve un identificatore ActivityPub (Actor, Post o Comment) alla riga
 * locale corrispondente, che l'URI appartenga a questa istanza o a un'altra.
 *
 * E' il contatore della serializzazione (che trasforma righe locali in
 * documenti ActivityStreams): qui si fa il percorso inverso, necessario per
 * elaborare "Like"/"Announce"/"Undo"/"inReplyTo" ricevuti nell'inbox, il cui
 * "object" e' sempre un URI e mai una riga di database.
 *
 * - Se l'URI appartiene al dominio locale, l'oggetto viene cercato per id
 *   (l'identificatore canonico locale e' sempre "/posts/{uuid}",
 *   "/comments/{uuid}" o "/@{username}", mai un "uri" salvato in tabella).
 * - Se l'URI appartiene a un dominio remoto, l'oggetto viene cercato nella
 *   cache locale tramite la colonna "uri" (post/commenti ricevuti in
 *   precedenza via "Create") o, per gli Actor, recuperato al volo tramite
 *   {@see RemoteActorResolver} se non ancora noto.
 */
final class ObjectResolver
{
    public function __construct(
        private readonly RemoteActorResolver $remoteActorResolver,
    ) {}

    public function resolveActor(string $uri): ?Actor
    {
        $actor = Actor::query()->where('uri', $uri)->first();

        if ($actor !== null) {
            return $actor;
        }

        // Alias locali "/@nome", "/users/nome", "/c/slug" (profile URL dei
        // Group): evita di trattarli come Actor remoti. Lemmy e altri spesso
        // usano l'URL profilo come object del Follow.
        $localUsername = $this->localActorUsernameFromUri($uri);

        if ($localUsername !== null) {
            return Actor::query()
                ->where('is_local', true)
                ->where('preferred_username', $localUsername)
                ->where('status', Actor::STATUS_ACTIVE)
                ->first();
        }

        return $this->remoteActorResolver->resolveByUri($uri);
    }

    private function localActorUsernameFromUri(string $uri): ?string
    {
        $host = parse_url($uri, PHP_URL_HOST);
        $domain = (string) config('openbook.domain');

        if (! is_string($host) || strcasecmp($host, $domain) !== 0) {
            return null;
        }

        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

        if (preg_match('#^/@([A-Za-z0-9_]+)$#', $path, $matches) === 1) {
            return mb_strtolower($matches[1]);
        }

        if (preg_match('#^/users/([A-Za-z0-9_]+)$#', $path, $matches) === 1) {
            return mb_strtolower($matches[1]);
        }

        if (preg_match('#^/c/([A-Za-z0-9_]+)$#', $path, $matches) === 1) {
            return mb_strtolower($matches[1]);
        }

        return null;
    }

    public function resolvePost(string $uri): ?Post
    {
        $localId = $this->localPathSegment($uri, 'posts');

        if ($localId !== null) {
            return Post::query()->find($localId);
        }

        return Post::query()->where('uri', $uri)->first();
    }

    public function resolveComment(string $uri): ?Comment
    {
        $localId = $this->localPathSegment($uri, 'comments');

        if ($localId !== null) {
            return Comment::query()->find($localId);
        }

        return Comment::query()->where('uri', $uri)->first();
    }

    /**
     * Risolve un "object" che puo' indifferentemente essere un post o un
     * commento (caso tipico di "Like"/"Announce"/"inReplyTo", che nella
     * rappresentazione ActivityStreams sono sempre semplicemente "Note").
     */
    public function resolvePostOrComment(string $uri): Post|Comment|null
    {
        return $this->resolvePost($uri) ?? $this->resolveComment($uri);
    }

    /**
     * Estrae l'UUID da un URI locale nella forma "/{segment}/{uuid}",
     * restituendo null se l'URI non appartiene a questa istanza o non
     * rispetta il formato atteso. Il confronto sul dominio ignora un'
     * eventuale porta su entrambi i lati, per restare corretto sia in
     * produzione (dominio senza porta) sia in sviluppo locale
     * (es. "localhost:8000").
     */
    private function localPathSegment(string $uri, string $segment): ?string
    {
        $uriHost = parse_url($uri, PHP_URL_HOST);
        $localHost = parse_url('//'.config('openbook.domain'), PHP_URL_HOST);

        if (! is_string($uriHost) || ! is_string($localHost) || strcasecmp($uriHost, $localHost) !== 0) {
            return null;
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);

        if (preg_match('#^/'.preg_quote($segment, '#').'/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
