# Snapshot — Roadmap

A developer provisioning tool for TYPO3: pull database + fileadmin from any environment
to your local machine, fast. **Not a backup tool.**

See [CONCEPT.md](./CONCEPT.md) for the full concept, architecture, and rationale.

Legend: `[ ]` todo · `[~]` in progress · `[x]` done

---

## M1 — Skeleton & quality tooling ✅

*Goal: a pushable, CI-green extension skeleton with all quality gates wired up.*

- [x] `composer.json` (`yellow-twins/snapshot`, extension key `snapshot`, PHP ^8.2, TYPO3 ^13.4 || ^14)
- [x] `ext_emconf.php`
- [x] Directory structure + `Configuration/Services.yaml` (autowiring)
- [x] PHPStan at **`max`** (`phpstan.neon` + phpstan-typo3 rules) — green
- [x] Psalm (L6), php-cs-fixer (TYPO3 coding standards), Rector (v13↔v14)
- [x] PHPUnit config (Unit + Functional), typo3/testing-framework
- [x] GitHub Actions CI matrix: TYPO3 v13 + v14 × PHP 8.2 / 8.3 (lint, cs, stan, psalm, tests)
- [x] Repo hygiene: `README`, `LICENSE` (GPL-2.0+), `CHANGELOG`, `CONTRIBUTING`, issue templates, `.editorconfig`, `.gitignore`, `.gitattributes`
- [x] XLIFF language file stub (en default)
- [x] DDEV v13 playground testbed (extension bind-mounted + symlinked, TYPO3 recognizes it as active)

**Done:** quality gates green in a real TYPO3 v13 install. CI matrix still to be confirmed on GitHub once pushed.

---

## M2 — Pillar B core: CLI pull over SSH (the hero) ✅

*Goal: `snapshot:pull --from=live` gets DB + fileadmin onto local. This alone replaces the throwaway scripts.*

- [x] `ConfigurationLoader` — parse `.snapshot.yaml` + `%env(...)%` interpolation + schema validation with helpful errors
- [x] `TransportInterface` + `SshTransport` (Symfony Process, key auth, ConnectTimeout)
- [x] `FileSourceInterface` + `RsyncFileSource` (incremental, excludes)
- [x] `DatabaseDumpService` — remote **mysqldump** (core has no `database:export`!) → stream → local `mysql` import; two-pass so excluded tables keep schema but lose data
- [x] `DatabaseConnectionResolver` — local via ConnectionPool, remote via reading composer-mode `settings.php` over the transport
- [x] Command `snapshot:pull` (`--from`, `--db`, `--files`, `--dry-run`, `--yes`) with confirmation
- [x] Command `snapshot:doctor` — preflight (local tools, SSH, remote settings.php/fileadmin/mysqldump)
- [x] Command `snapshot:list-envs`
- [x] Commands registered + smoke-tested in the playground; quality gates green
- [x] **Prefer typo3_console** `database:export` (remote) + `database:import` (local): TYPO3
      resolves credentials itself. mysqldump/mysql kept as fallback for remotes without it.
      `helhum/typo3-console` added as a hard requirement.
- [x] **Real end-to-end pull verified** against a live staging server (weltacker-stage):
      167 pages / 968 tt_content / 554 sys_file imported locally in ~5s.
- [x] Unit tests: ConfigurationLoader, TablePatternMatcher (exclude patterns), DatabaseConnection, ByteFormatter, rsync stats parser
- [x] Transfer-size preview before pulling (fileadmin via rsync --stats; DB via information_schema when credentials are available)

**M2 complete:** proven end-to-end against a live server, unit + functional tested, size preview in place.

---

## M3 — Safety layer: GDPR scrubbing + post-pull hooks 🚧

*Goal: pulled data is legally safe to hold locally and the site is immediately runnable.*

- [x] `ScrubbingService` — runs on the local DB after import; built-in defaults (fe_users
      anonymization, sys_log truncate) + config `scrub_rules` overrides. `{uid}` templates for
      per-row uniqueness via `ScrubExpressionBuilder`.
- [x] `PostPullHookRunner` — `cache_flush`, `referenceindex`, `reset_admin_password`
      (bcrypt via PasswordHashFactory)
- [x] `--no-scrub` opt-out with explicit warning; scrubbing on by default
- [x] Unit tests for `ScrubExpressionBuilder`; **functional test** of `ScrubbingService` on
      MariaDB (anonymize / truncate / config override); verified end-to-end against weltacker-stage
- [ ] Secret-in-DB scrubbing presets (API tokens stored in DB tables) — `.env`/config files are
      never transferred anyway (DB dump + fileadmin only)
- [ ] Presets for common EXT (cart/orders, news)

**Done:** a prod pull lands anonymized by default, cache-flushed, with a known admin login
(`SnapshotDev.1234!`), with unit + functional coverage. Remaining: more EXT presets.

---

## M4 — DDEV integration ✅

*Goal: `ddev snapshot-pull --from=live` — meet developers where they work.*

- [x] DDEV custom web commands wrapping the CLI (`commands/web/snapshot-{pull,doctor,list-envs}`)
- [x] Installable as a DDEV add-on (`install.yaml`, `ddev add-on get yellow-twins/snapshot`)
- [x] Verified: add-on installs and `ddev snapshot-list-envs` runs in the playground

**Done:** `ddev add-on get yellow-twins/snapshot` installs the commands; `ddev snapshot-pull`,
`ddev snapshot-doctor`, `ddev snapshot-list-envs` work inside the container.

---

## M5 — Pillar A: hardened backend module 🚧

*Goal: the "I have backend admin but no SSH" path, with the full 9-layer security model. This is the answer to the ns_backup advisories.*

Slice 1 (done):
- [x] Backend module (`Configuration/Backend/Modules.php`, tools area, admin-only) + icon + XLIFF labels
- [x] Controller + Fluid template (idle screen from the design + security-blocked state)
- [x] `ExportGuard` — kill-switch (disabled by default), IP allowlist (env), MFA required. Safe by default.

Slice 2a (done):
- [x] Theme-aware rendering (TYPO3 `light-dark()` CSS vars) + interactive idle screen (JS module
      via import map, CSP-compliant). Verified in a real v13 backend behind a real MFA login.

Slice 2b (done):
- [x] Prepare/export flow (fileadmin self-export) + **single-use, expiring, non-guessable
      download tokens** stored outside the web root (SHA-256-named, never the plaintext token).
- [x] Download endpoint: atomic single-use consume, streamed attachment, file deleted after.
- [x] `AuditLogger` — every prepare/download/rejection recorded to a dedicated audit log.
- [x] Verified end-to-end in the backend: prepare → ready (countdown) → download → consumed → audited.
      Unit tests for the token service (single-use, expiry, hash-not-in-filename, purge).
- [x] Found + fixed a real bug: the download-token parameter must not be named `token`
      (collides with the backend route CSRF token) — renamed to `dl`.

Slice 2c (next):
- [ ] Database self-export: local `database:export` + anonymization WITHOUT touching the live DB
      (temp-database method; needs CREATE DATABASE — capability check + clear fallback message).
- [ ] Notification mail on every export; step-up re-auth before prepare; async export for large sites.
- [ ] Backend UI additive-only scrub-table selection (never weaken the baseline).

---

## M6 — Release & community

- [ ] Full documentation on the docs.typo3.org theme
- [ ] TER handover for the `snapshot` key (abandoned since v9)
- [ ] Publish to Packagist, tag `v1.0.0`
- [ ] README leads with the security/trust story and explicit non-goals
- [ ] Announcement (TYPO3 Slack, community channels)

---

## Deliberate v1 scope cuts (documented, not silent)

- Transport: SSH only (kubectl = v2). File-source: rsync only (S3/object-storage = v2).
- Local fileadmin only; remote FAL storages beyond local = later.
- MySQL/MariaDB first; Postgres/SQLite later.
- No multi-site / multi-database installs in v1.
- Pull-first: `push` guarded, push-to-production disabled by default.

## Post-v1 backlog

- `KubectlTransport` + `S3FileSource` drivers (Kubernetes support).
- Postgres/SQLite dump/import.
- Cross-version pull with automatic `upgrade:run` (v13-remote → v14-local).
- Selective/partial pulls beyond tables (content trees, single page subtrees).
