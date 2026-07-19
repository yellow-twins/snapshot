# Snapshot — TYPO3 Extension

> Spin up a working TYPO3 locally in minutes — pull database + fileadmin from any
> environment (DEV/Stage/Live) via CLI, or via a hardened backend module when you
> only have backend access.
>
> **This is a developer provisioning tool, NOT a backup tool.** No scheduler, no
> off-site storage, no restore feature. Backups are the job of DevOps / hosting.

**Status:** Concept / pre-development
**Target:** TYPO3 v14 (primary), v13 LTS
**License:** GPL-2.0-or-later
**Vendor / repo:** `yellow-twins/snapshot` — `git@github.com:yellow-twins/snapshot.git`
**Namespace:** `YellowTwins\Snapshot\`

---

## 1. What problem it solves

Every TYPO3 developer rebuilds the same throwaway scripts: SSH in, `mysqldump` /
`typo3 database:export`, `rsync` fileadmin down, import locally, fix the context. It's
slow, error-prone, and undocumented tribal knowledge (cf. koehnlein.dev, in2code gists).

Snapshot turns that into a maintained, secure, first-class extension. Core use cases:
- Onboard a new dev: working local TYPO3 with real data in minutes.
- Refresh a stale local database from Stage/Live on demand.

**Explicit non-goals:** backups, scheduling, off-site storage, disaster recovery,
restore-to-production. That's DevOps/hosting territory.

---

## 2. Market position (validated 2026-07)

- **Backup category (ns_backup / Backup Plus by NITSAN):** established, but a *backup*
  tool — and hit by multiple 2025 security advisories (command injection, XSS,
  predictable resource location → unauthenticated backup download). We deliberately do
  NOT compete here; we're not a backup tool.
- **Dev env-sync:** only throwaway bash scripts and gists exist. **No packaged, secure,
  community-grade product.** This is the white space Snapshot occupies.
- `filefill` (ichhabrecht): related but different (lazy on-demand remote file fetch, not
  a full sync).

---

## 3. Architecture — two transports, one goal (pull an environment to local)

Same goal, two access levels. Each independently enable/disable-able.

- **Pillar B — CLI over SSH (the hero):** runs from the dev machine. DB via remote
  `typo3 database:export`, fileadmin via `rsync`. **Extension is a local `require-dev`
  dependency only — production is never touched.** Zero remote footprint, zero remote
  attack surface.
- **Pillar A — Backend module over HTTP:** for the common "I have backend admin but no
  SSH" case (restricted/managed hosting). *Only here* the extension is installed on the
  remote, and *only here* the full security model applies. This is Pillar A's reason to
  exist beyond backups.

### Driver abstraction (future-proofing, incl. Kubernetes)

Transport and file-source are pluggable drivers so "every server is different" and even
K8s become tractable:

- **Transport driver:** `ssh` (v1) → `kubectl exec` (v2, `kubectl exec pod -- typo3
  database:export` + `kubectl cp`).
- **File-source driver:** `rsync` (fileadmin on disk/PVC, v1) → `s3`/object-storage
  (v2 — in K8s fileadmin usually lives on a FAL S3 driver, so you sync from the bucket,
  not rsync).

v1 ships SSH + rsync only. The interfaces are cut so kubectl/s3 drivers dock later
without rewriting the core. No K8s promise for v1 — but the door stays open.

```
snapshot/
├── Classes/
│   ├── Command/            # Pillar B: snapshot:pull / push / doctor / list-envs
│   ├── Backend/            # Pillar A: backend module + controllers
│   ├── Transport/          # SshTransport (+ later KubectlTransport) via interface
│   ├── FileSource/         # RsyncFileSource (+ later S3FileSource) via interface
│   ├── Service/
│   │   ├── DatabaseDumpService.php     # wraps typo3 database:export
│   │   ├── FileadminSyncService.php
│   │   ├── ScrubbingService.php        # GDPR anonymization (core feature)
│   │   ├── PostPullHookRunner.php      # cache:flush, admin pw reset, context, ...
│   │   ├── ConfigLoader.php            # .snapshot.yaml + .env interpolation
│   │   └── SnapshotManifest.php
│   ├── Security/           # Pillar A: ExportGuard, AuditLogger
│   └── Doctor/             # environment preflight checks
├── Configuration/
│   ├── Backend/Modules.php
│   ├── Services.yaml
│   └── Sets/Snapshot/      # v14 site set
├── Resources/Private/Language/  # XLIFF (en default, de shipped)
├── ddev/                   # DDEV add-on: custom commands + install.yaml
└── Documentation/
```

---

## 4. Configuration — everything sensitive via .env

Paths, hosts, IPs, ports, credentials → `.env` (never backend-editable, never committed).
`.snapshot.yaml` holds non-secret structure and interpolates `%env(...)%`.

```yaml
# .snapshot.yaml
environments:
  live:
    transport: ssh                     # ssh | kubectl (v2)
    host: "%env(SNAP_LIVE_HOST)%"
    user: "%env(SNAP_LIVE_USER)%"
    port: 22
    path: "%env(SNAP_LIVE_PATH)%"
    file_source: rsync                 # rsync | s3 (v2)
  stage:
    transport: ssh
    host: "%env(SNAP_STAGE_HOST)%"
    path: "%env(SNAP_STAGE_PATH)%"

defaults:
  scrub: true                          # GDPR anonymization ON by default
  db_exclude: [cache_*, "[bf]e_sessions", sys_log, sys_history, sys_file_processedfile]
  rsync_excludes: ["_processed_/**", "_temp_/**"]
  post_pull: [cache_flush, referenceindex, reset_admin_password, set_dev_context]

guards:
  push_to_live: false                  # pull-first; push guarded, prod blocked by default
```

---

## 5. Security model (Pillar A — backend module) — defense in depth

Direct answer to the ns_backup advisories. 2FA alone is not enough.

| # | Control | Purpose |
|---|---|---|
| 1 | Dedicated permission (not just "is admin") | Least privilege; default = nobody |
| 2 | IP allowlist in `.env` (NOT backend-editable) | VPN gating; survives a compromised admin |
| 3 | MFA required (core Mfa API) | Stolen password ≠ access |
| 4 | Step-up re-auth right before export | Session age ≠ authorization |
| 5 | Audit log + notification mail | Exfiltration never unnoticed |
| 6 | Secret scrubbing (.env, AdditionalConfiguration, keys, be_users hashes) | No credential leak |
| 7 | GDPR anonymization (fe_users, orders, addresses) | Safe to copy to a dev laptop |
| 8 | Global kill-switch via `.env` | Prod can forbid export entirely |
| 9 | Download token: single-use, expiring, non-guessable path | Fix the exact ns_backup flaw |

CLI (Pillar B) also logs who pulled what/when. GDPR scrubbing applies to both pillars.

---

## 6. Key features / DX

- **DDEV integration:** ships DDEV custom commands (`ddev snapshot-pull --from=live`) and
  an installable DDEV add-on. Generic Docker/Composer still supported.
- **`snapshot:doctor`** — preflight: SSH reachable? remote `typo3` present? rsync there?
  permissions ok? Big DX win, cuts support load.
- **Post-pull hooks** — cache:flush, reference index, `upgrade:run`, reset BE admin pw to
  a dev default, set Development context, disable prod mailer/payment. "Data present" →
  "immediately runnable".
- **Dry-run + size preview** — "this will transfer ~3.2 GB" before committing.
- **Selective pull** — specific tables / fileadmin subfolders only.
- **Sensible DB exclude defaults** — no pulling 5 GB of cache.
- **Non-interactive mode + clean exit codes** — CI-friendly.
- **Version caveat** — v13-remote → v14-local schema drift; recommend same version, offer
  `upgrade:run` hook; document clearly.

---

## 7. Quality gates (non-negotiable — community credibility)

- **PHPStan** at `max` (L9/L10) for greenfield — start strict. (Fallback L7 if pragmatic.)
- **Psalm** (L6+), **php-cs-fixer** + **phpcs** with TYPO3 ruleset, **Rector** for v13↔v14.
- **PHPUnit** Unit + Functional; high coverage — transport/scrub/hook services are mockable.
- **CI matrix:** v13 + v14 × PHP 8.2 / 8.3.
- **English everywhere** (code, comments, docs). Backend module + commands **i18n via
  XLIFF** (en default, de shipped).
- **Docs** on docs.typo3.org theme; CHANGELOG, CONTRIBUTING, issue templates; GPL-2.0+.

---

## 8. v1 scope & milestones (both pillars; B leads)

- **M1 — Skeleton:** structure, CI matrix (v13+v14), composer, quality tooling, docs stub.
- **M2 — Pillar B core (hero):** `snapshot:pull` (DB `database:export` + fileadmin rsync),
  `.snapshot.yaml` + `.env`, `snapshot:doctor`, dry-run. Ships value fastest, no remote risk.
- **M3 — GDPR scrubbing + post-pull hooks:** the "safe & runnable" layer. Applies to B.
- **M4 — DDEV add-on + custom commands.**
- **M5 — Pillar A backend module:** export + single-use download token, behind the full
  security layer (permission, IP allowlist, MFA, step-up, audit+mail, kill-switch).
- **M6 — Release:** docs, TER handover for key `snapshot` (abandoned since v9), announce.

---

## 9. Scope cuts for v1 (documented honestly)

- Transport: SSH only (kubectl = v2). File-source: rsync only (S3/object-storage = v2).
- Local fileadmin only; remote FAL storages beyond local = later.
- MySQL/MariaDB first; Postgres/SQLite later.
- No multi-site / multi-database installs in v1.
- Pull-first: `push` guarded, push-to-prod disabled by default.

---

## 10. Open questions

- TER key `snapshot` handover timeline (abandoned since v9).
- Default anonymization ruleset — config-driven per project (schemas differ); ship sane
  presets for core tables + common EXT (news, cart/orders?).
- fileadmin size ceiling for the backend (Pillar A) path — document threshold, steer to B.
