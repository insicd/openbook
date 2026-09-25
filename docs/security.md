> Documentation: [English](README.md) · [Italiano](README.it.md)

## Security and privacy

- Passwords hashed with modern algorithms via Laravel's native mechanisms
  (bcrypt), no custom algorithm.
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
