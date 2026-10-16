> Documentation: [English](README.md) · [Italiano](architecture.it.md)

# Using Openbook

Openbook lets you publish, follow people, hold conversations and participate in
communities and events. Your account can interact with people on the same
instance and with accounts on other compatible Fediverse services.

## Registration and interface language

During registration you can enter uppercase letters in your username:
it is saved in lowercase. You can customize your profile's display name.

The language icon in the top bar opens the available languages. Before login,
Openbook uses your browser's language; an explicit choice is remembered on
the same browser for one year and takes precedence. New accounts inherit
the language used during registration.

After login, your account preference takes precedence. Changing it in the bar
also updates **Settings → Account → Language**, including for visits from
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

## Profile and settings

Open **Settings** from the user menu or **Edit profile** on your profile. You
can update your name, biography, avatar, cover and up to eight fields shared
between Links and Additional information. Images are previewed before saving.
Profile changes are communicated to remote followers.

Preferences let you choose interface language and initial visibility for new
posts. With **Protected account**, you approve follow requests. You can choose
whether to appear in suggestions and whether to allow indexing of your public
posts. Opting out of suggestions keeps your profile reachable by its exact
address.

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
