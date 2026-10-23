> Documentation: [English](README.md) · [Italiano](roadmap.it.md)

# Product status and limitations

Current version: **26.38.rc1**. Release changes are in the
[CHANGELOG](../CHANGELOG.md). Stable versions use `year.week`; follow-up patch
candidates of the same week add `.rcN` and come after that stable version.
The footer shows the installed version.

## Available features

- Guided installation, updates and administration suitable for shared hosting.
- Profiles, posts, attachments, comments, likes, shares and quotes.
- Local and remote follows, search, chronological Home, followed hashtags and World.
- Notifications, private conversations and push notifications when enabled.
- Public/private local communities and membership of compatible remote communities.
- Local and remote events, comments and participation according to source support.
- Viewing and voting on remote polls; creation, voting, scheduled closure and
  answer details for local polls.
- Moderation, domain blocks, queue management and database maintenance.
- Instance statistics for signed-in users, asynchronous charts and an admin-managed cache.
- Images and audio; optional local video processing configured by the administrator.

Interoperability is refined over time: each remote service can offer a different
set of content and interactions.

## Known limitations

- Mentions while writing recognize local and already-known remote accounts.
  Search for a new remote account by `user@domain` first.
- Private conversations are available in Messages. Posts with Direct visibility
  instead use recipients mentioned in their text.
- World and search work with content known to the instance, without crawling or
  indexing the entire Fediverse. Search respects visibility and indexing consent
  for other authors’ public content. Keyword search for posts and comments
  covers local content.
- Remote text is displayed safely, without arbitrary HTML. Remote images depend
  on files remaining available at their source.
- Local polls exclude private conversations, comments, events and publishing to
  remote communities. Options, mode, deadline and visibility stay fixed; early
  closure and changing the deadline are deferred.
- Local pinned-post and memorial-profile management remain outside the available
  features; compatible remote information is displayed.
- The configured comment-depth limit is not yet applied in the interface: the
  reply tree is displayed on the post page.
- The administration panel does not include IP bans or advanced federation
  signature-debugging tools.

For daily use, see [Using Openbook](architecture.md); for server differences and
delivery management, see [Federation](federation.md).
