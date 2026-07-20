# Snapshot

> Spin up a working TYPO3 locally in minutes. Pull database + fileadmin from any
> environment (DEV / Stage / Live) — from the CLI over SSH, or through a hardened
> backend module when you only have backend access.

**Snapshot is a developer provisioning tool, not a backup tool.** No scheduler, no
off-site storage, no restore-to-production. Backups are the job of your DevOps / hosting.
Snapshot exists to get real data onto a developer machine fast — onboarding a new dev, or
refreshing a stale local database.

- **CLI over SSH (the hero):** runs from your machine, installed as `require-dev` only —
  production is never touched. DB via `typo3 database:export`, fileadmin via `rsync`.
- **Backend module over HTTP:** for the "I have backend admin but no SSH" case, hardened by
  defence in depth — admin-only, an environment-configured IP allowlist, mandatory MFA, a
  master kill-switch, single-use expiring download tokens, server-side GDPR anonymization, and
  an audit log. *(Notification mail and step-up re-authentication are planned for a later release.)*
- **GDPR-safe by default:** pulled and exported data is anonymized — front-/backend user PII and
  password hashes are scrubbed before it ever lands locally.
- **DDEV-native:** ships `ddev snapshot-pull` commands and a DDEV add-on.

## Status

**Public beta (0.9.0).** Targets TYPO3 **v14** (primary) and **v13** LTS, PHP ≥ 8.2. The CLI pull
and the backend module (database + fileadmin, anonymized) are feature-complete and tested; the
remaining pre-1.0 work is community feedback plus notification mail and step-up re-auth for the
backend module. See [ROADMAP.md](./ROADMAP.md).

## Requirements

- TYPO3 13.4+ or 14
- PHP 8.2+
- For CLI pulls: SSH access to the source environment, `rsync` available locally and remotely.

## Backend module configuration (Pillar A)

The backend export module is **off by default** and gated by environment variables (kept out of
the backend so a compromised admin cannot weaken them):

| Variable | Default | Meaning |
|---|---|---|
| `SNAPSHOT_BACKEND_ENABLED` | *(unset = off)* | Master kill-switch. Set to `1` to enable the module. |
| `SNAPSHOT_ALLOWED_IPS` | *(unset = no restriction)* | Comma-separated IPs/ranges allowed to export. |
| `SNAPSHOT_REQUIRE_MFA` | `1` (required) | Set to `0` to drop the mandatory-2FA check (only in trusted/local contexts). |
| `SNAPSHOT_ALLOW_UNSCRUBBED` | *(unset = off)* | Set to `1` to unlock an opt-in, clearly marked **raw** (un-anonymized) database export for local debugging. |

GDPR anonymization (user PII and password hashes) is a guarantee of the backend export and is not
disable-able from the backend — unless `SNAPSHOT_ALLOW_UNSCRUBBED=1` is set in the environment,
which unlocks an opt-in, clearly marked **raw** export for local debugging. For the **CLI pull**,
scrubbing is controlled per project in `.snapshot.yaml` (`defaults.scrub`) and per run with `--no-scrub`.

## License

GPL-2.0-or-later. See [LICENSE](./LICENSE).
