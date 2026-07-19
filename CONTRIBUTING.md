# Contributing to Snapshot

Thanks for your interest! Snapshot aims to be a well-engineered, community-grade TYPO3
extension. Contributions are welcome — please keep the quality bar high.

## Ground rules

- **English everywhere.** All code, code comments, commit messages, and documentation are
  written in English. User-facing strings live in XLIFF language files (never hardcoded).
- **Quality gates must pass.** Before opening a PR, run `composer ci` locally:
  - PHPStan at `max`
  - Psalm
  - php-cs-fixer (TYPO3 coding standards)
  - PHPUnit (unit + functional)
- **Tests required.** New behavior needs tests. The transport, scrubbing, and hook
  services are designed to be mockable — cover them.
- **Semantic versioning** and a Keep-a-Changelog entry for user-facing changes.

## Scope

Snapshot is a **developer provisioning tool**, not a backup tool. Please keep proposals
within that scope (see [CONCEPT.md](./CONCEPT.md)). Backup/scheduler/off-site-storage
features are intentionally out of scope.

## Local setup

```bash
composer install
composer ci
```

## Security

If you find a security issue, please **do not** open a public issue. Email
security@yellow-twins.com instead.
