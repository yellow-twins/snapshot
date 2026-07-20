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
- **Backend module over HTTP:** for the "I have backend admin but no SSH" case, behind a
  9-layer security model (dedicated permission, `.env` IP allowlist, MFA, step-up re-auth,
  audit log + mail, secret scrubbing, GDPR anonymization, kill-switch, single-use tokens).
- **GDPR-safe by default:** pulled data is anonymized before it lands locally.
- **DDEV-native:** ships `ddev snapshot-pull` commands and a DDEV add-on.

## Status

🚧 **Alpha / in development.** Targets TYPO3 **v14** (primary) and **v13** LTS, PHP ≥ 8.2.
See [ROADMAP.md](./ROADMAP.md) and [CONCEPT.md](./CONCEPT.md).

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

GDPR anonymization and secret scrubbing are guarantees of the backend export and are not
disable-able from the backend. For the **CLI pull**, scrubbing is controlled per project in
`.snapshot.yaml` (`defaults.scrub`) and per run with `--no-scrub`.

## License

GPL-2.0-or-later. See [LICENSE](./LICENSE).
