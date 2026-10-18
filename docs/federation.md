> Documentation: [English](README.md) · [Italiano](federation.it.md)

# Federation

Federation lets an Openbook account follow and interact with accounts on other
ActivityPub-compatible servers. Each server has its own rules, processing times
and available features.

## Finding and following a remote account

Search for the full address, such as `name@domain.example`. From the remote
profile you can follow the account and read available content. A protected
account may require follow approval; the request stays pending until answered.

Openbook fetches remote profiles and some content when needed and keeps a local
copy. Fetching may take time or fail when the source server is temporarily
unreachable. Cron handles pending updates and delivery attempts.

A confirmation in the interface means the action has been recorded locally
and federation work queued. Receipt by the other server may happen later.
Following, liking, commenting, sharing and voting follow this behavior.

## Content and updates

The instance receives relevant content: followed accounts, replies to known
content, local mentions and community activities. Visiting a profile or post
may fetch more content. **World** shows public remote posts already known to
the instance, not every post in the Fediverse.

Edits and deletions are communicated between servers. Update timing depends on
cron and the availability of participating instances. Remote servers may apply
different rules for keeping copies. Content warnings and reading permissions
also apply to imported posts.

Alongside ordinary posts, compatible articles, images, videos, polls and events
are recognized. Remote text is displayed safely, preserving supported links
and attachments. The author-declared language appears beside the date when
available; Openbook does not infer language by analyzing the text.

Remote images load from the source server. If the file is removed or the source
blocks access, the image may become unavailable. Content from some services
depends on their federation behavior: for example, Threads makes posts received
after following available when the account has enabled Fediverse sharing.

## Remote profiles

Profile fields, biography and images are updated from information published by
the source. Links and text remain subject to Openbook’s display protections.
The **Pinned posts** tab appears when visible pinned content exists; pins stay
separate from the ordinary timeline. Fetch errors preserve the last valid list.

An account suspended by its own server retains imported content, while new
interactions are stopped. A later reactivation can restore them after the
profile is updated. Local moderation stays separate from source-server decisions.

When a remote account announces a verifiable move, Openbook notifies local
followers. The notification opens the destination, where each user can choose
Follow; existing follows stay unchanged. The old profile points to the
destination; previous content stays associated with its original author.
Profiles marked as memorial accounts by the source are identified as such.
Local management of memorial profiles and pinned posts remains outside the
current feature set.

## Moving to Openbook from another server

In **Settings → Account migration → Moving from another instance**, add your previous
account using its full address (`name@example.org`) or ActivityPub actor URL. Openbook saves
its canonical actor URL as a public `alsoKnownAs` alias. You can manage up to ten
aliases; removing one may prevent a move that has not yet been verified.

Adding an alias only prepares the destination. Start the move from the previous
account on its original server. Followers on compatible servers may then send
normal follow requests to Openbook. With a protected Openbook account, these
requests still need your approval. Posts, attachments and private messages are
not imported.

If someone on this Openbook instance follows your old account, a verified Move
towards your new local account notifies them and links to the new profile.
They choose whether to follow it; their old follow stays unchanged. A normal
profile update alone does not send this notification.

## Moving from Openbook to another server

On your new server, add your old Openbook account as an alias. Then open
**Settings → Account migration → Move your account** in Openbook, enter the new account address
and choose **Verify destination**. Openbook checks the alias on the new profile.
Confirm the destination with your current Openbook password and the confirmation
checkbox to announce the move.

Openbook marks your profile with the new address and sends a signed Move to
your remote followers using the ordinary delivery queue. Local followers receive
a notification and choose whether to follow the new account. Your old account
remains usable: you can publish, interact and receive new followers. Posts,
attachments and messages stay associated with the old account.

**Remove the move indication** in settings removes the notice and sends a
profile update. Followers already transferred stay where they are. You can
then announce another move using the same verification and confirmation steps.

## Communities

Local communities are available in **Communities**. You can create, join and
publish to them according to assigned permissions. Private communities require
approval and restrict access to authorized members. Management can be shared
with community moderators.

To find a remote community, search `name@domain`, join and open its profile.
The composer is available to members according to remote-server support.
The bell lets you receive in-app notifications for posts relayed by the
community. Notifications in another service’s client also depend on its own
preferences.

The list separates **Your communities**, **Local communities** and **Remote
communities**. You can cancel a membership request while it is pending.
Local polls are supported in local communities; publishing them to remote
communities is intentionally excluded.

## Local polls

For creation and voting, follow the [poll guide](architecture.md#polls).
The federated format follows ActivityPub polls as used by Mastodon; actual
support on other platforms may vary.

Openbook calculates results for its own polls. Single-choice answers are final;
a remote client can submit multiple-choice options separately. Each participant/
option pair counts once and a person counts as one participant even when they
select several answers.

Embedded remote votes received before the deadline can be processed later.
For answers provided only by a remote address, fetching must also happen before
the deadline. Closed results can therefore still change when the inbox is
processed. Current permissions and blocks continue to apply.

Cron publishes polls and distributes edits, results and closure to authorized
recipients. Remote voters can receive updates even without following the author.
Restricted polls stay within their audience; relays receive only eligible
public content. Vote Notes do not appear as comments or messages.

Individual identities per option are visible only to the author. Shared results
contain aggregates only. Deleted accounts’ contributions stay in the totals
with identities detached.

## Remote polls

Polls received from other servers appear in post cards and detail pages. You
can vote while a poll is open and you have the necessary permissions. The choice
is confirmed after local persistence and queuing delivery to the remote author;
the interface preserves your answer and vote date.

Counts and percentages come from the source server. Openbook highlights your
choice without adding it independently to remote results. Updates may arrive
later. Opening the post detail can fetch current state; results are not
continuously polled.

A known deadline appears beside **Poll**. **Poll open** indicates an unknown
deadline; **Poll closed** indicates closure. Missing counts appear as
**Results updating**. Inconsistent data remains visible with percentages omitted.
If the source changes or removes a selected option, your original answer stays
available.

Quoted polls are read-only. Content warnings, post permissions and
administrator-applied blocks still apply.

## Events

Primary times use the event's own time zone. When your local time differs, a
smaller note shows “… in your time zone”, including the date where needed.
New events default to your personal time zone; change it if the event takes
place elsewhere. Editing an existing event keeps its assigned time zone.

**Events** contains upcoming and past appointments. Authenticated users can
create public or unlisted events with description, cover, time zone,
physical/online/hybrid location and open, moderated or external participation.
Organizers can edit, cancel or delete their events and manage moderated
participation requests.

Remote events offer interest, comments and participation when supported by the
source. Counts and updates depend on the organizing server. Sharing an event in
a private conversation preserves the recipient’s existing access permissions.
Content warnings also protect event descriptions and media.

## Relays and administration

Use **Administration → Relays** to manage reception and publication through
Mastodon, ActivityPub or LitePub-compatible relays. The panel shows subscription
state, including waiting for a reciprocal follow. Enable the directions needed
for the protocol and instance policy.

Disabling publication or removing a relay also stops pending deliveries to it.
Restricted, unlisted, remote and private-community content is excluded from
public relay publication. Domain blocks still apply.

Federation uses signed requests and checks on contacted addresses. The instance
must be reachable over HTTPS and have a correct clock. Check queue state, errors
and blocked domains in the administration panel when an interaction stays
pending. For network settings, cron and maintenance, see
[configuration](configuration.md).

After updating, follow the [update procedure](install.md#updating-an-existing-instance).
General cron already includes polls and events: these features need no extra
cron entries or permanent workers. To prepare polls manually:

```bash
php artisan openbook:process-polls --max-time=5
php artisan openbook:deliver --max-time=5
```

The first command records closures and prepares deliveries; the second attempts
sending. Instances using a separate queue backend must also consume the internal
`local_delivery` queue, or use `openbook:deliver`.

### Recovering ignored activities after an update

When a version adds support for previously ignored content, an administrator
can reprocess retained activities:

```bash
php artisan openbook:reprocess-inbox
```

The command queues `ignored` and `pending` entries, excluding `processed` and
`failed` entries. With a database queue, then run
`php artisan openbook:process-inbox`; with `QUEUE_CONNECTION=sync`, processing
happens immediately. This command changes the queue and has no preview: use it
for manual recovery. It can queue jobs already present; previously processed
entries are recognized and skipped.
