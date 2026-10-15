# Remote poll fixtures

These are invented, anonymized Mastodon-shaped payloads inspired by the local
inbox inspection recorded in `analysis/REMOTE_POLLS.md`. They contain no copied
user text, real actor URIs or inbox identifiers. The single-choice sequence
preserves the observed pattern: a Create with zero votes followed by an Update
with four option counts totaling 46. Missing `updated` is deliberate: poll count
updates need not be status edits.

Additional cases cover multiple choice (7 voters, 16 selections), timestamp
closure, and an open poll with unknown counts and expiry. Tests control the
clock; fixtures retain fixed timestamps. These fixtures do not depend on the
local database or on a reachable remote server.

The vote fixture follows the primary protocol documentation: exact option name,
Question URI in `inReplyTo`, Create/Note addressed to the poll author, and distinct
activity/object IDs. It describes the wire format; the production serializer
and voting flow are scheduled for sprint 3.

Sources checked on 2026-10-15:

- https://docs.joinmastodon.org/spec/activitypub/#polls
- https://github.com/mastodon/mastodon/blob/main/app/serializers/activitypub/vote_serializer.rb
- https://github.com/mastodon/mastodon/blob/main/app/serializers/activitypub/note_serializer.rb

Production normalization currently accepts 2–50 options, up to 1,000 Unicode
characters per name, and exact distinct names. It rejects ambiguous modes,
malformed values and oversized documents rather than truncating options or
changing names, since that would change the meaning of a vote. Missing/null
counts remain unknown; explicit integer zero remains zero. These are Openbook
interoperability limits, not limits claimed for the ActivityStreams vocabulary.
