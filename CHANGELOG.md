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
