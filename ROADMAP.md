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

## M2 — Pillar B core: CLI pull over SSH (the hero) 🚧

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
- [ ] Transfer-size preview before pulling
- [~] Unit tests (ConfigurationLoader done) + functional tests + more coverage on the DB command builder

**Remaining for M2:** transfer-size preview, more unit/functional coverage. Core is proven end-to-end.

---

## M3 — Safety layer: GDPR scrubbing + post-pull hooks 🚧

*Goal: pulled data is legally safe to hold locally and the site is immediately runnable.*

- [x] `ScrubbingService` — runs on the local DB after import; built-in defaults (fe_users
      anonymization, sys_log truncate) + config `scrub_rules` overrides. `{uid}` templates for
      per-row uniqueness via `ScrubExpressionBuilder`.
- [x] `PostPullHookRunner` — `cache_flush`, `referenceindex`, `reset_admin_password`
      (bcrypt via PasswordHashFactory)
- [x] `--no-scrub` opt-out with explicit warning; scrubbing on by default
- [x] Unit tests for `ScrubExpressionBuilder`; verified end-to-end (scrub + hooks) against weltacker-stage
- [ ] Secret-in-DB scrubbing presets (API tokens stored in DB tables) — `.env`/config files are
      never transferred anyway (DB dump + fileadmin only)
- [ ] Presets for common EXT (cart/orders, news) and a functional test of ScrubbingService

**Mostly done:** a prod pull lands anonymized by default, cache-flushed, with a known admin
login (`SnapshotDev.1234!`). Remaining: more presets + a functional test.

---

## M4 — DDEV integration

*Goal: `ddev snapshot-pull --from=live` — meet developers where they work.*

- [ ] DDEV custom host commands wrapping the CLI (`ddev/commands/host/`)
- [ ] Installable as a DDEV add-on (`install.yaml`, add-on metadata)
- [ ] Docs section for the DDEV workflow

**Done when:** `ddev add-on get yellow-twins/snapshot` installs the commands and `ddev snapshot-pull` works.

---

## M5 — Pillar A: hardened backend module

*Goal: the "I have backend admin but no SSH" path, with the full 9-layer security model. This is the answer to the ns_backup advisories.*

- [ ] Backend module (`Configuration/Backend/Modules.php`) + controller + Fluid templates + XLIFF
- [ ] `ExportGuard` — dedicated permission, IP allowlist (`.env`), MFA required, step-up re-auth, kill-switch
- [ ] `AuditLogger` — sys_log + notification mail on every export
- [ ] Single-use, expiring, non-guessable download token (fixes the predictable-resource-location flaw)
- [ ] Async export for large sites (Scheduler / Messenger), streamed archive
- [ ] Reuse M3 scrubbing on the export path
- [ ] Tests for the security guards (the highest-risk code)

**Done when:** a permitted admin behind the IP allowlist, with MFA + step-up, can export and download once via an expiring token; every attempt is audited and mailed; the feature is off unless explicitly enabled.

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
