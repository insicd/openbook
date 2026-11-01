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
data for client rendering. Limits and key preparation are documented in the
[API guide](api/README.md).
