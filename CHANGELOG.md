# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
