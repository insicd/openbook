> Documentation: [English](README.md) · [Italiano](README.it.md)

## Security and privacy

- Passwords protected with bcrypt hashing.
- Authentication with rate limiting on login attempts and on resending
  verification emails.
- Actor private keys encrypted at rest, never exposed by APIs/logs/errors.
- Session cookies `HttpOnly` and `secure` in production, CSRF protection on
  all forms.
- No third-party analytics, trackers, advertising pixels, CDNs, or mandatory
  remote fonts: the interface uses only CSS and assets served locally.
- The installer never shows secrets (passwords, tokens) after the procedure
  completes and locks itself permanently at the end.

To report vulnerabilities see [`SECURITY.md`](../SECURITY.md).

## Experimental REST API

The first REST/OAuth sprint is available in production as well. Resources
require a valid Bearer, scope api and active user/actor; website credentials
do not authorize API calls. OAuth uses Authorization Code with PKCE S256 and
CSRF-protected consent. Settings can revoke accesses together with refresh
tokens. The API preserves visibility, ownership and private-community boundaries
and exposes no administration or moderation operations. Caches are private and
304 is evaluated after access checks. Names, content and URLs remain untrusted
data for client rendering. OAuth signing keys are generated automatically and
stored encrypted in the database with APP_KEY. Preserve both in backups and
across deployments; unreadable existing keys fail without automatic replacement.
Limits and deployment guidance are documented in the
[API guide](api/README.md).

## Email verification and publishing

Until the email address is verified, local accounts can browse, personalize their
profile, follow people, like and reshare existing posts. Creating posts (including
quotes and polls), comments, events, private messages and communities requires
verification. Video publication also waits for verification. This applies to the
website and REST API, including existing unverified accounts.

Open the verification page from the notice to request another email. Confirming
the signed link enables publishing without another login. Existing tokens continue
to work. Editing or deleting existing content keeps its current permissions.
Email verification does not replace visibility, ownership or moderation checks.
