> Documentation: [English](README.md) · [Italiano](README.it.md)

## Tests

Account departures (#117):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/AccountMigrationTest.php tests/Feature/AccountAliasesTest.php tests/Feature/Federation/RemoteAccountMoveTest.php
```

The SQLite tests cover verification, confirmation, ordinary delivery, profile
signatures, notification behavior, removal and subsequent moves.

Account arrival and aliases (#117):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/AccountAliasesTest.php tests/Feature/Federation/RemoteAccountMoveTest.php tests/Unit/Infrastructure/Security/LinkedData/LinkedDataSignatureTest.php
```

These cover ownership, canonical public aliases, limits/removal, profile Update
signatures, verified local destinations, unchanged follows and ordinary incoming
follow approval. The #116 remote destination and profile discovery regressions
remain covered. No database migration is added by this stage.

Personal time zones:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test tests/Feature/UserTimezoneTest.php tests/Feature/UserDatePresentationTest.php tests/Unit/Support/UserDateTest.php
node tests/Browser/timezones.cjs
OPENBOOK_TEST_TIMEZONE_MYSQL=1 APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test tests/Feature/UserTimezoneMysqlTest.php
```

The MySQL check uses `OPENBOOK_TEST_MYSQL_HOST`, `PORT`, `SOCKET`, `USERNAME`
and `PASSWORD` (with the same prefix). It creates and drops a random schema,
never migrating the application's database, and verifies rollback, nullability
and defaults. Browser checks use in-memory SQLite and cover detection, device
changes, explicit preferences, guests, events, missing JavaScript/Intl/cookies
and POST responses never being repeated. Verification screenshots are saved
in the system temporary directory.

Language selection and authentication pages (#103):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test tests/Feature/LocalePreferenceTest.php tests/Feature/GuestLocaleTest.php tests/Feature/Auth tests/Feature/SettingsTest.php tests/Feature/HomeGuestFeedTest.php
node tests/Browser/locales.cjs
```

The optional browser check uses the production layout and CSS with in-memory
SQLite. It checks desktop/mobile, keyboard navigation, form submission and
the guest menu without JavaScript. Like the other browser checks it requires
Playwright; `BROWSER_EXECUTABLE_PATH` can point to an installed Chromium.

Finnish translations can also be checked with:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test tests/Unit/Support/FinnishTranslationsTest.php tests/Unit/Support/CompactNumberTest.php tests/Feature/FinnishLocaleTest.php
OPENBOOK_BROWSER_LOCALE=fi node tests/Browser/locales.cjs
```

These checks cover all five files, keys, placeholders and plural ranges,
as well as selection, registration, dates and the decimal separator.

Local poll foundations (#121) can be checked with:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Polls/LocalPollFoundationsTest.php tests/Feature/Posts/PostPublicationStagerTest.php
```

These cases cover persistence, validation, canonical/outbox Questions,
audience, immutability, deletion and expiry after video staging.
`LocalPollMysqlPersistenceTest` reuses the cases in isolated random schemas and
adds migration rollback and EXPLAIN checks. Enable it with
`OPENBOOK_TEST_POLLS_MYSQL` and the `OPENBOOK_TEST_MYSQL_*` variables described
below, selecting the class with `--filter=LocalPollMysqlPersistenceTest`.

Local/federated vote collection (#121, sprint 2):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Polls/LocalPollVotingTest.php tests/Feature/Federation/LocalPollVoteImportTest.php
```

`LocalPollMysqlVotingTest` repeats domain cases in isolated MySQL schemas and
checks real concurrent submissions and EXPLAIN plans. Enable the same opt-in
variables and select `--filter=LocalPollMysqlVotingTest`. No new migration is
introduced by sprint 2. These checks exercise services and inbox processing directly.

Poll results and scheduled closure (#121, sprint 3):

```bash
php artisan migrate
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Polls/PollResultsTest.php tests/Feature/Console/ProcessPollsCommandTest.php
```

The new migration adds transient `poll_distributions` snapshots. For manual
checks, `php artisan openbook:process-polls --max-time=5` prepares jobs and
`php artisan openbook:deliver --max-time=5` sends them; `openbook:cron` already
includes both. `--limit=1 --batch-size=1` on the poll command lets you inspect
partial progress and resume in a later pass. `PollResultsMysqlTest` adds real
concurrent cron processes and EXPLAIN checks of every recipient UNION branch,
using the same isolated-schema opt-in variables described below.

Poll creation/voting UI (#121, sprint 4):

```bash
php artisan route:clear
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Polls/LocalPollUiTest.php tests/Feature/Polls/RemotePollUiTest.php
node tests/Browser/polls.cjs
```

The optional browser check renders production Blade partials against in-memory
model fixtures, loads production JS/CSS and checks desktop/mobile fields,
Unicode limits, modal reset/edit isolation, results switching and vote feedback.
It requires Playwright and an installed browser (`BROWSER_EXECUTABLE_PATH` can
select one). Native links are checked with JavaScript disabled; HTTP tests cover
native option controls and submissions. PHPUnit uses an isolated routes cache
path, avoiding stale routes from the developer instance.

For post-detail back navigation, an optional browser check uses the production
JS/CSS with a local HTTP fixture for ordinary form redirects:
`node tests/Browser/post-back.cjs`. It requires Node.js, Playwright and an
installed browser on the development machine; these are not application or
hosting requirements. `BROWSER_TYPE` (default `chromium`) and
`BROWSER_EXECUTABLE_PATH` can select an existing browser. This check complements
PHPUnit and does not replace a test on an actual iOS installed web app.

For the profile editor, `node tests/Browser/profile-fields.cjs` checks the
production Blade partial, JS and CSS with legacy values, add/remove buttons,
the shared eight-field limit and desktop/mobile layouts. It also requires PHP
and uses the same optional browser tooling described above.

The project uses PHPUnit. The suite runs by default on in-memory SQLite (see
`phpunit.xml`), so it does not need a MySQL database to run:

```bash
php artisan test
```

Author-only poll answers (#121, sprint 5) are covered by `PollVotersTest`;
`PollVotersMysqlTest` repeats pagination/deletion cases and checks actual query
plans with 211 target voters and 1,000 voters belonging to another poll. The
browser fixture also checks option links, keyboard navigation and pagination
on desktop/mobile, without accessing the application database.

Remote poll foundations are covered by `RemotePollDocumentTest` and
`RemotePollPersistenceTest`, using anonymized fixtures in
`tests/Fixtures/Federation/Polls`. An optional MySQL/MariaDB check runs the same
persistence, voting and database-queue transaction cases, plus migration rollback,
two-process concurrency and `EXPLAIN` checks:

```bash
OPENBOOK_TEST_POLLS_MYSQL=1 php artisan test --filter=RemotePollMysqlPersistenceTest
```

It uses `OPENBOOK_TEST_MYSQL_HOST`, `OPENBOOK_TEST_MYSQL_PORT`,
`OPENBOOK_TEST_MYSQL_USERNAME` and `OPENBOOK_TEST_MYSQL_PASSWORD`; optionally
`OPENBOOK_TEST_MYSQL_SOCKET` selects a Unix socket. The account must be able to
create and drop schemas named `openbook_polls_test_*`. Each case creates its own
random schema, runs the application migrations and drops that schema afterward.
It never migrates or clears the local application database or the database named
by `OPENBOOK_TEST_MYSQL_DATABASE`. Use a throwaway server. Without the opt-in
flag these tests are skipped; with it, connection or migration errors fail.
Import and the voting service are connected to the web interface.
`RemotePollVotingTest` covers single/multiple choice, idempotency, rollback and
retry; `DeliverPollVotesCommandTest` covers consumption by the existing command.
`RemotePollUiTest` covers feed, detail, HTML/JSON forms, privacy across viewers,
content warnings and previous choice snapshots.
When production configuration is cached, point `APP_CONFIG_CACHE` to a nonexistent
path to preserve the local cache while using the test configuration:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --filter=Poll
```

### Manual poll verification

After updating this branch on a development instance, refresh stale caches and
apply migrations (the automated tests do not modify the application database):

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan migrate
```

If the deployment normally caches configuration/routes, regenerate those caches
through its normal deployment procedure after updating.

1. Open an already imported remote post using `polls.post_id`, rather than
   `source_item_id`. Check option text, aggregate counts and closed/open state.
2. Create a public local poll with a five-minute duration. Start with two options,
   add/remove options, and try both single and multiple choice (up to six).
   Include an attachment and a content warning in one example.
3. From a second account, show results, return to choices and vote. Confirm the
   personal answer and final choice; another account must not see that answer.
   As the author, open “Answer details”, switch options and inspect participants.
   The same URL must reject another account, including a moderator/admin.
4. Repeat in a private local community and with followers-only visibility.
   Check that an excluded account cannot open or vote on the post. Edit the
   text: definition and visibility must remain fixed.
5. After five minutes, check closure, then run `php artisan openbook:cron`.
   This processes the inbox, records closures, prepares snapshots and attempts
   ordinary queued delivery. No extra cron entry is required. A preparation-only
   pass is `php artisan openbook:process-polls --max-time=5`.
6. With a second test federation instance, verify publication, incoming single/
   multiple votes and results/closure Updates. These live interoperability
   checks complement the automated HTTP fixtures; they require test accounts.

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
- remote suspension (`RemoteSuspensionTest`): origin flags and incoming Updates,
  reactivation without undoing local moderation, preserved cached content,
  read-only UI, rejected direct interaction requests, suspended comments under
  other authors' posts/events, and automatic sharing paused without losing its
  setting;
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
  name/biography/profile fields with synchronization of the name on the federated Actor,
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
