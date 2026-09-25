> Documentation: [English](README.md) · [Italiano](README.it.md)

## Roadmap and project status

Current version: **26.38.rc1**. Release notes are in Italian in
[`CHANGELOG.md`](../CHANGELOG.md).

### Versioning

From 26.34 onward, a stable release uses `YY.week` (this release is `26.34`,
codename Lovable Pancake). Follow-up patch candidates of the same week are
`26.34.rc1`, `26.34.rc2`, and so on. `26.34.rc1` comes *after* the `26.34`
stable (it is not a pre-release before it). The `0.x` versions in the changelog
are pre-stable history.

NodeInfo and the User-Agent use the technical version (`26.38.rc1`); the footer
shows `26.38.rc1` (release candidates have no codename).

- ✅ **Phase 1 — Structure and installation**: project, configuration,
  installer, database, authentication, administrator account, local profiles.
- ✅ **Phase 2 — Local social domain**: posts, images, nested comments, likes,
  shares, local follows, feed, notifications.
- ✅ **Phase 3 — Federated identity**: `Person` Actor, WebFinger, NodeInfo,
  content negotiation, inbox/outbox, HTTP signatures.
- ✅ **Phase 4 — Social federation**: remote search,
  `Follow`/`Accept`/`Reject`, `Create`/`Update`/`Delete`, `Like`, `Announce`,
  `Undo`, MySQL queue, retry, cron; then (0.5.x–0.6.x) profile/settings, World,
  on-demand outbox and replies, admin panel, signed fetch, live notifications.
- ✅ **Phase 5 — Community** (0.7.x): local and remote `Group` Actors,
  membership, wall, FEP-1b12 Announce, Lemmy/Friendica, moderators, Local/Remote
  list. Further polish (dedicated member list, community avatar/cover) remains
  possible without blocking Phase 6.
- 🚧 **Phase 6 — Security and interoperability** (in progress on the first
  stable): types `Article`/`Video`/`Image`, remote media in the gallery,
  `/users/…` URIs, Lemmy Accept, Pixelfed Atom fallback, Wafrn blog API, Photos
  roll on the profile, World → `/mondo/scopri`. Still to strengthen: NodeBB and
  other edge cases; optional local download of remote media; dedicated
  recipients for direct messages.

The project does not move to the next phase until the previous phase's tests
are green.

### Known limitations

- Mentions on *write* resolve **local** Actors and remote ones **already in
  cache** (`@utente` / `@utente@dominio`); an unknown remote handle is not
  resolved on the fly via WebFinger at compose time. On *receive*, a mention of
  a local Actor correctly generates a notification.
- "Direct" messages (`direct` visibility) have no dedicated recipient list:
  they are visible to the author and to whoever is mentioned in the text. A
  conversation UI is deferred.
- Remote content is reduced to plain text (no arbitrary HTML): labeled links
  (`[text](url)` from `<a href>`) and images in `attachment` as remote URLs
  remain. This is an explicit security choice.
- Remote content in the inbox is cached only if relevant (followed author,
  reply to something known, local mention). In addition: remote profile
  (`RemoteOutboxFetcher`, with Atom fallback) and replies of the remote post
  (`RemoteRepliesFetcher`). It is not a complete index of the fediverse.
  Keyword search (`LocalSearchQuery`) covers *local* content; for remotes,
  `utente@dominio` resolution remains.
- Remote images use `media.remote_url` (hotlink): if the origin blocks or
  removes the file, the gallery can appear empty. Local attachments stay on
  `storage/app/public` → `public/storage`, with no CDN.
- The `OPENBOOK_COMMENT_MAX_DEPTH` limit is in configuration but not yet
  applied in the UI: a post's entire comment tree is loaded in one page.
- The control panel covers moderation, blocked domains, queue, and settings;
  IP bans and an HTTP-signature debug tool remain out of scope. Staff
  promotion: UI and CLI (`openbook:make-admin` / `openbook:make-moderator`).
