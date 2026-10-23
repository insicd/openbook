> Documentation: [English](README.md) · [Italiano](architecture.it.md)

# Using Openbook

Openbook lets you publish, follow people, hold conversations and participate in
communities and events. Your account can interact with people on the same
instance and with accounts on other compatible Fediverse services.

## Registration and interface language

During registration you can enter uppercase letters in your username:
it is saved in lowercase. You can customize your profile's display name.

The language icon in the top bar opens the available languages:
Italiano, English and Suomi (Finnish). Before login,
Openbook uses your browser's language; an explicit choice is remembered on
the same browser for one year and takes precedence. New accounts inherit
the language used during registration.

After login, your account preference takes precedence. Changing it in the bar
also updates **Settings → Account and privacy → Language**, including for visits from
other devices. Switching language reloads the current page. On mobile,
signed-in users can find the selector in the menu of additional options.

## Publishing and reading posts

Write in the composer. Icons in the lower toolbar open advanced controls such
as title, content warning, attachments, visibility and polls. Opening another
panel preserves the values you have already entered.

Text supports Markdown, links, hashtags and mentions. You can attach images and
audio; local video uploads are available when enabled by the administrator.
Videos require processing and publish when it finishes. Content warnings let
readers choose when to reveal text and attachments. Click an image to open it
full screen; use arrows to browse and Esc to close.

Icons below a post let you like, comment and share it. The three-dot menu offers
available content actions, including editing and deleting your own posts.
Shares retain the link to the original and appear in the sharer’s timeline.

In lists, the card header opens the local detail page. The author’s name opens
their profile; a remote post’s date opens the original on its source server.
The Back control on the detail page returns to the previous page when the post
was opened from the same instance.

## Visibility

Choose visibility before publishing:

| Visibility | Who can read |
| --- | --- |
| Public | Anyone; the post may appear in public lists. |
| Unlisted | Anyone with the link; the post stays out of public lists. |
| Followers only | The author and authorized followers, according to current permissions. |
| Direct | The author and mentioned recipients; use Messages for conversations. |

Private communities apply their own access rules. Sharing a link or quoting
restricted content preserves those rules. A copy already delivered to another
server also depends on that server’s rules and administration.

## Polls

The poll icon opens two initial answer fields. Add options one at a time up to
six, with at most 50 characters per answer. The post text is the question.
Allow one or multiple answers and choose a duration from five minutes to seven
days; the initial duration is one day.

After publication, options, mode, deadline and visibility stay fixed. You can
correct the post text. Duration starts at actual publication, including posts
with a video awaiting processing.

**Vote** confirms a final choice. **Preview results** shows aggregates before
voting; **Back to options** restores selection. After voting, your answer and
“You voted on …”, with date and time, appear beside the participant total.
The poll closes at its deadline. In multiple-choice polls one person can
contribute to several answers: percentages may add up to more than 100%, while
participants are counted once.

The author can open **Answer details** to inspect local and remote voters per
option, with pagination. Other users see aggregates and their own choices.
The information icon beside the actions explains these rules. Polls are not
anonymous to their author. Deleted accounts’ contributions remain in the totals
with their identities detached.

Polls are available in personal posts and local communities, including private
ones. Private conversations, comments, events and publishing to remote
communities are excluded. Closure is scheduled: its date and time stay as
chosen at publication. For polls from other servers, see
[remote polls](federation.md#remote-polls).

## Home, hashtags and search

Home shows your posts, posts from followed accounts and their shares in reverse
chronological order. Following a hashtag also brings relevant public posts
already known to the instance into Home. Followed hashtags are private account
preferences.

While you follow fewer than 10 people and hashtags combined, a welcome panel
above the posts suggests how to populate your feed. Accepted follows of local
and remote people and followed hashtags count; communities and pending requests
are excluded. The panel disappears at 10 and returns if the total drops below 10.
It appears only at the start of the feed. The actions for exploring communities
and trending topics appear after the staff and before the suggested people;
“Discover more people” follows the suggestions.
The “From the fediverse” list appears only while you follow zero people and
hashtags; after your first follow, the button at the bottom lets you keep exploring.

Search finds people, hashtags and visible events; keyword search for posts and
comments covers local content. To find a remote account,
use its full address `user@domain`. Mentions while writing recognize local and
already-known remote accounts: search for a new remote address first. Inclusion
of other authors’ public posts in search also depends on their indexing consent.

Trending shows hashtags recently used in local public or unlisted content.
Home and World require JavaScript to load their feeds. Profiles, communities
and hashtag pages also provide pagination links with JavaScript disabled.

## Stats

The **Stats** link in the footers opens a page for signed-in users, with no
staff role required. The page shows retained public posts over the
last 360 complete hours (UTC), and languages of retained public posts and
events over the last two years, combined by language. Content without a
declared language is excluded from the language chart and its totals.

The page also shows accounts by instance, combined regardless of type,
weekly post volume and distinct weekly authors over the last two years.
We show the ten leading instances and languages; remaining languages are
grouped under Other.
Volume and active authors each show a single weekly total series, without
instance filters or a local/remote split. Author counts
must not be added across weeks. On MySQL, the shared query favours the existing
index on visibility, status and publication date; SQLite and MariaDB keep
the query without an index hint.

The hashtag chart includes each of the last 24 calendar months’ top 10
(including the partial current month), plus the top 10 over the entire period.
Each month shows at most ten hashtags: outside the top 10 a series has a
gap, keeping its identity and colour when a hashtag returns. The legend contains only the ten most used
over the period: hover or keyboard focus highlights a series; tooltips also
identify hashtags outside the legend. Ranges stay in UTC; generation times
use the visitor’s timezone.

Post timeline charts also show configured retention durations as vertical
lines with a legend for pertinent and non-pertinent posts. Thresholds use
the dataset generation time and are interpolated between buckets, without
changing data or querying the corpus again. These are duration references:
actual pruning uses first import time, while chart axes use publication time.
Disabled or out-of-range thresholds are hidden; equal durations share one line.
The event calendar, non-timeline distributions and delivery buckets do not
show these references.

Geography shows countries associated with content places, combining posts
and events without a selector. It counts only content with a place, without
computing a corpus-wide total or the percentage of geotagged content.
Unspecified countries and small groups
are combined; no coordinates or authors’ locations are shown. The calendar
groups events published over the last two years by starting month, including
future start dates. Communities show how many eligible public communities
published or shared retained posts, including remote Group actors. Weeks refer
to the original post publication date; each community and each post count once
per week, even if multiple communities shared the same post.

Attachments show images and videos in posts: file totals and weekly trends,
with one dataset shared by both charts. A post may have multiple files or
both types; audio, documents and external embeds are excluded. On MySQL,
`JOIN_PREFIX(att)` favours starting from attachments; SQLite and MariaDB keep
the query without optimizer hints. Polls show the
weekly number of open or closed polls in eligible public posts, without
accessing votes or participants. The join includes only posts with a poll;
no corpus-wide denominator is computed. Weeks without polls have value zero.

Provenance counts processed deliveries of public posts, including known posts,
starting with migration `2026_10_22_000000_create_post_delivery_metrics`. This is
neither a unique-post count nor an HTTP request counter: successful Create,
Update and Announce processing counts once per post delivery. Sources distinguish
relays, inbox without an identified relay (labelled “Direct delivery”), fetch/discovery, RSS and unknown.
The direct-delivery label does not imply that the sender is the original author.
Already known RSS entries can contribute on each import.

Only collection metadata, source identities and daily UTC buckets by source are
stored. There is no journal of post URIs/hashes, authors or communities. Later
deletions, blocks and suspensions do not rewrite history. Private posts and
private communities are excluded at counting time; core message acceptance
checks remain unchanged.

Increments run after the outermost post commit, on a dedicated connection and
in a separate transaction. All errors are swallowed without logging, retrying
or failing the import. Post rollback discards the callback. Collection is best
effort: increments may be lost and internal retries may count more than once;
absolute accuracy is not promised.

Collection also includes public-event deliveries from inbox/relays, outboxes,
URL resolution and refresh. Migration
`2026_10_22_000001_unify_content_delivery_metrics` renames the existing post
buckets to `stats_content_delivery_buckets`, preserving counts and collection
start time (`content_delivery`). Posts and events increment the same daily
source bucket, without a content-type dimension. Events contribute from the
activation of event tracking; earlier deliveries are not reconstructed.
DeliveryStats uses the same post-commit isolation for both types. Repeated
Create, Update and Announce count; later cancellation or deletion does not
retract counts. The chart shows one combined total without a selector.
Queries aggregate the shared buckets without reading content, authors or
participants.

Dataset results are cached through the configured Laravel store (database/file
supported, no worker or Redis required). TTLs in `config/stats.php` have a
300-second minimum: rhythm 5 minutes; instance counts, geography and delivery
15 minutes; other datasets one hour. Weekly volume/authors share one result,
as do attachment totals/trends. Keys contain dataset version, metric and a
shared generation rotated only by the admin command, without user IDs. Responses
remain authenticated and HTTP `private, no-store`.

A small grey information button below each chart shows the effective refresh
frequency and a single “Data updated on” date, using cache insertion time in the visitor’s
timezone, on hover, keyboard focus or touch. Cache hits retain these dates.
If the store is unavailable, results are computed directly and no cache
insertion is claimed. Failed queries are never cached. Misses use a bounded
cache lock; old in-flight computations cannot publish into a new generation.
No uncommitted snapshot is cached.

**Admin → Database → Maintenance → Clear statistics cache** rotates only the
Stats namespace. Other application cache entries and collected delivery buckets
are preserved; datasets rebuild on subsequent requests. The POST requires an
administrator and CSRF protection. Results refresh only when their TTL expires
or an administrator explicitly clears the statistics cache. Edits, visibility
changes, suspensions, deletion, domain blocks and settings changes do not
invalidate cached results. Crossing an hour/day boundary does not bypass the
TTL either. Current query filters and UTC windows are evaluated on the next
recalculation; this cache policy does not change the filters themselves.
Refresh configuration and route caches when deploying these changes.

Each chart loads its dataset as you approach it on the page. Failed requests
can be retried independently. Statistics
respect visibility and moderation exclusions, including suspended or deleted
accounts and blocked domains. All aggregate statistics include accounts with
`discoverable=false` and `indexable=false`.
They describe retained content, not the entire Fediverse: deletion and
retention can change the series, and zero does not prove that nothing was
published historically.

## Profile and settings

Open **Settings** from the user menu or **Edit profile** on your profile. You
can update your name, biography, avatar, cover and up to eight fields shared
between Links and Additional information. Images are previewed before saving.
Profile changes are communicated to remote followers.

Preferences let you choose interface language, time zone and initial visibility
for new posts. With **Protected account**, you approve follow requests. You can choose
whether to appear in suggestions and whether to allow indexing of your public
posts. Opting out of suggestions keeps your profile reachable by its exact
address.

In **Settings → Account and privacy → Time zone**, **Automatic — from your device** follows
your browser's time zone, including when travelling or changing devices. Choose
a specific time zone to always use it; you can return to Automatic at any time.
New and upgraded accounts start in Automatic. Interface language is independent
of your chosen time zone.

Messages, poll deadlines and profile dates follow your time zone. Guests also
see times in their browser's time zone. Detection needs no geolocation permission;
in Automatic mode, without JavaScript, cookies or a valid detected zone,
Openbook uses the instance time zone. Changing your time zone does not change
deadlines or publication instants.

## Notifications and messages

The bell opens recent notifications; the Notifications page contains the full
list. Badges update while the tab is visible. Push notifications are available
when enabled and permitted in the browser.

**Messages** contains private conversations. New messages in the same
conversation update its notification. Opening the conversation marks it read;
reading general notifications keeps message read status separate. Threads use
arrival order on the instance, while each message’s displayed date remains its
original publication date.

## World, communities and events

**World** shows public remote content already known to the instance. **Discover**
suggests remote accounts to follow. These pages depend on content received or
fetched by the instance and do not represent a complete Fediverse directory.

**Communities** separates your memberships, local communities and remote ones.
**Events** shows upcoming and past appointments and lets authenticated users
create them. For memberships, bells, participation and interaction with other
servers, see the [federation guide](federation.md).

## Running an instance

Administrators manage settings, accounts, moderation, blocked domains, queues
and maintenance from the administration panel. Moderators can use functions
assigned to their role; staff roles grant no access to another author’s
individual poll answers. In **Appearance**, an optional public feed on the
guest homepage (off by default) moves instance information to the left and
shows public posts already visible to the instance in the centre.

For installation and updates, follow the [installation guide](install.md).
For cron, maintenance, retention and videos, see
[configuration](configuration.md); for current limits, see the
[roadmap](roadmap.md).
