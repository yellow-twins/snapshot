# Security Policy

Snapshot moves real production data onto developer machines, so its security posture is a
first-class concern. This document explains how to report an issue and what the extension
guarantees.

## Reporting a vulnerability

**Please do not open a public issue for security problems.** Report them privately to
**hello@yellow-twins.com** with:

- a description of the issue and its impact,
- steps to reproduce (a proof of concept if possible),
- the affected version(s).

We aim to acknowledge reports within a few working days and will coordinate a fix and disclosure
with you.

## Supported versions

Security fixes target the latest released `1.x` line.

## Security model

The CLI pull (Pillar B) runs from a developer machine as a `require-dev` dependency and only issues
read-only export commands on the source; the extension need not be installed on the server.

The backend module (Pillar A) is **disabled by default** and, when enabled, is protected by several
independent controls:

- **Admin-only** by module registration.
- **Kill-switch** — `SNAPSHOT_BACKEND_ENABLED` must be set explicitly.
- **IP allowlist** — optional, configured in the environment, not editable from the backend.
- **Mandatory MFA** — active two-factor authentication required by default.
- **Single-use, expiring download tokens** — artifacts are stored outside the public web root under
  a SHA-256-derived name (the 192-bit random token is never persisted), served only through the
  authenticated backend route, claimed atomically, and deleted after one download.
- **GDPR anonymization** — the database export is scrubbed server-side against a throwaway copy; the
  live database is never modified. Front- and backend user PII and password hashes are replaced.
- **Audit log** — every prepare, download and rejection is recorded.

All controls that relax security (kill-switch, IP allowlist, MFA requirement, and the opt-in raw
export via `SNAPSHOT_ALLOW_UNSCRUBBED`) live in the environment, never in the backend UI, so a
backend admin alone can never weaken them or export un-anonymized data.

## Notes for operators

- The IP allowlist relies on TYPO3's resolved remote address. Behind a reverse proxy, configure
  `$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']` (and related settings) correctly, otherwise
  the client address cannot be trusted.
- Prepared artifacts live in the non-public `var/snapshot` directory (mode `0700`). They are removed
  after download and expired ones are purged on the next module request.
