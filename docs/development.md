> Documentation: [English](README.md) · [Italiano](README.it.md)

## Tests

The project uses PHPUnit. The suite runs by default on in-memory SQLite (see
`phpunit.xml`), so it does not need a MySQL database to run:

```bash
php artisan test
```

The suite covers bootstrap/installer/authentication, the local social domain
(posts, media, comments, reactions, follows, feed, notifications), identity and
social federation (`tests/Feature/Federation`,
`tests/Unit/Infrastructure/Security`), communities
(`tests/Feature/Communities`), and interoperability cases (Article/Video/
attachments, Pixelfed Atom fallback, Wafrn blog API, Lemmy Accept, World/
discover, profile photo roll). In particular:

- generation and verification of HTTP Signatures, `SsrfGuard` (rejection of
  private/loopback/reserved IPs, DNS that resolves to non-public addresses,
  resolution failures), WebFinger, NodeInfo, content negotiation on
  profile/post/comment (including visibility rules for anonymous requesters and
  the `Tombstone` representation), outbox/followers/following collections, and
  the full inbox lifecycle at the transport level (correctly signed activity,
  missing signature, tampered body, signing Actor mismatch, unsupported
  content-type, body too large, deduplication, shared inbox);
- `InboxActivityProcessor`: every activity type (`Follow` toward open and
  protected accounts, `Accept`/`Reject` of an outgoing follow, `Undo`,
  `Like`/`Announce` and their `Undo`, `Create` of a relevant post or reply,
  `Delete`, `Update` with a `Note` object and with a `Person`/`Group` object
  including rejection of a document that declares an id different from the
  signing Actor) and the case of an unknown signing Actor;
- `ActivityDelivery` and `DeliverActivityJob`: deduplication of shared inboxes,
  exclusion of local/not-yet-accepted followers, delivery rules for direct
  messages, correct HTTP signature of the outgoing request, permanent failure
  without a private key, retry on a non-2xx response;
- `RemoteActorResolver::resolveByUri()`/`resolveByHandle()`: fetch and cache
  with TTL, rejection of a document that declares an id different from the one
  requested, refusal to treat a local URI as remote, WebFinger resolution;
- the full "local action → activity delivered to a remote Actor" cycle
  end-to-end for `Follow`/`Unfollow`, `Like`/`Unlike`, `Announce`/`Unannounce`,
  publishing and deleting posts and comments (including the HTTP controllers);
- remote search (`/cerca`) and the profile page of a cached remote Actor
  (`/attori/{id}`, including the redirect to the canonical profile when the id
  corresponds to a local Actor);
- `RemoteFollowCollectionsFetcher` (`RemoteFollowCollectionsFetcherTest`):
  visiting a remote profile queries the `followers` and `following`
  collections (TTL `OPENBOOK_COLLECTIONS_CACHE_TTL_HOURS`, default 24h) for
  `totalItems` counts and a first-page sample, without writing into the local
  `follows` graph; join date comes from `published` on the Person document;
- `RemoteOutboxFetcher` (`RemoteOutboxFetcherTest`): on the first load of a
  remote Actor's profile page (or after cache expiry) the most recent public
  posts from their real outbox are fetched and shown, excluding replies,
  non-public posts, and any item that declares an author other than the outbox
  owner; if the outbox is a stub (only `totalItems`, typical of Pixelfed) the
  Atom feed is used; no new request before cache expiry; the attempt is still
  recorded when the remote server does not respond, so later loads are not
  slowed down; no mention notification is generated for content retrieved this
  way (it is not a "just happened" event);
- `RemoteRepliesFetcher` (`RemoteRepliesFetcherTest`, `SignedFetchTest`):
  opening a remote post (e.g. from the feed of someone you follow) queries the
  `replies` collection of the original Note (TTL
  `OPENBOOK_REPLIES_CACHE_TTL_HOURS`), also following Mastodon's typical `next`
  pagination (where the first page is often empty); the same Note GET updates
  like/share card counts from `likes`/`shares` `totalItems` (at most one extra
  GET per collection if the total is not inline); GETs are signed
  (authorized fetch) with the visiting user's key or a fallback local Actor;
  public/unlisted comments from third parties are cached without generating
  notifications; replies to already-known comments under the same post are
  nested correctly;
- follower/following lists (`FollowListTest`): public visibility for a local
  profile, exclusion of still-pending requests, correct follow/unfollow button
  state per row, redirect of a remote Actor's list when it actually corresponds
  to a local account, authentication required for a remote Actor's list.
- the Settings page (`SettingsTest`): authentication required, update of
  name/biography/links with synchronization of the name on the federated Actor,
  upload and replacement of the avatar (with removal of the previous file),
  rejection of a non-image file, change of interface language actually applied
  by the middleware, propagation of default visibility to the composer,
  synchronization of "protected account" between `user_settings` and the Actor,
  exclusion from suggestions when the account is no longer "discoverable",
  sending of a federated `Update` to remote followers when the public profile,
  the "Protected account" option, or the `discoverable`/`indexable` flags
  change (and its absence when only purely local preferences change); the
  profile image upload service (`ProfileImageUploaderTest`): separate paths for
  avatar/cover, removal of the previous file, type and size validation,
  resizing of oversized images, permissions of the directory created on first
  upload correct even with a restrictive PHP-process `umask` (also verified for
  post attachments in `MediaUploaderTest`); construction of the avatar/cover
  URL (`Tests\Unit\Domain\Profiles\ProfileTest`), to avoid regressions on the
  choice of the "public" disk instead of the `asset()` helper.
- the "World" section (`WorldTest`): the timeline shows only public remote
  posts already in cache and excludes both local posts and non-public remote
  ones; authentication required; ranking of accounts to discover (priority to
  accepted local followers, then to the most recent activity), exclusion of
  anyone with neither a local follower nor a post in cache, exclusion of anyone
  already followed by the visitor; `/mondo/scopri` page with the full list and
  infinite scroll.
- share visibility (`AnnounceVisibilityTest`): a shared post (local, from
  another local Actor, or remote) appears on the profile and in the personal
  feed of the person who shared it with the "shared this post" label; ordering
  by share time even when the original post is much older; no redundant label
  when sharing your own post; disappearance from the profile after undoing the
  share; no label on the original author's profile.

A small subset of tests (`Tests\Feature\Installer\InstallerMysqlFlowTest`)
specifically checks installer step 2 (connection and migration) against a
**real MySQL/MariaDB server**, because this behavior cannot be exercised
reliably with SQLite. These tests skip themselves (`markTestSkipped`) if they
do not find a reachable server with the credentials given by the environment
variables `OPENBOOK_TEST_MYSQL_HOST`, `OPENBOOK_TEST_MYSQL_PORT`,
`OPENBOOK_TEST_MYSQL_DATABASE`, `OPENBOOK_TEST_MYSQL_USERNAME`,
`OPENBOOK_TEST_MYSQL_PASSWORD` (sample values already present in
`phpunit.xml`). To actually run them, start a throwaway MySQL/MariaDB instance
with those credentials before launching the suite.
