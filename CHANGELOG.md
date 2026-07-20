# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Backend module, first slice (M5): admin-only "Snapshot" tools module with a security gate
  (`ExportGuard` — kill-switch disabled by default, optional IP allowlist, mandatory active MFA)
  and the design's entry screen. The prepare/export/download flow with single-use tokens follows.
- DDEV add-on (M4): `ddev add-on get yellow-twins/snapshot` installs `ddev snapshot-pull`,
  `ddev snapshot-doctor`, and `ddev snapshot-list-envs` web commands.
- Transfer-size preview before a pull: fileadmin size via `rsync --stats`, database size via
  `information_schema` when credentials are available (shown in dry-run and before the
  confirmation prompt). Completes M2.
- GDPR anonymization + post-pull hooks (M3). After the DB import, `ScrubbingService`
  anonymizes personal data on the local copy (built-in fe_users/sys_log defaults, plus
  `scrub_rules` overrides with `{uid}` templates). `PostPullHookRunner` runs `cache_flush`,
  `referenceindex`, and `reset_admin_password` (to a known dev password). Scrubbing is on by
  default; `--no-scrub` opts out with a warning. Verified end-to-end against a live server.

- Project skeleton: composer setup, quality tooling (PHPStan max, Psalm, php-cs-fixer,
  Rector), CI matrix (TYPO3 v13 + v14), documentation stubs. (M1)
- CLI pull over SSH (Pillar B core): `snapshot:pull`, `snapshot:doctor`,
  `snapshot:list-envs`. Config via `.snapshot.yaml` with `%env(...)%` interpolation.
  Database transferred with mysqldump/mysql (two-pass, honours table excludes); fileadmin
  via rsync. Remote DB credentials resolved from the remote's `settings.php`. (M2)
- Optional explicit `db:` block per environment (env-interpolated) for hostings where the
  real DB credentials live only in web-context env vars and are absent from the SSH shell.
- `snapshot:doctor` now actively verifies remote database reachability and gives a
  targeted hint when the DB host is container-internal. (M2)
- Prefer typo3_console for the database transfer: `database:export` on the remote (when
  available) and `database:import` locally. TYPO3 resolves the connection itself, so no
  credentials need to be extracted. `helhum/typo3-console` is now a hard requirement;
  mysqldump/mysql remains a fallback for source servers without typo3_console. Verified
  end-to-end against a live staging server. (M2)
