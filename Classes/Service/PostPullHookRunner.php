<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Service;

use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Runs post-pull hooks that make a freshly pulled site immediately usable locally: align the
 * database schema to the local code, flush caches, rebuild the reference index, and reset backend
 * admin passwords to a known development value.
 */
final class PostPullHookRunner
{
    /**
     * Development password set for all backend admins by the reset_admin_password hook.
     */
    public const DEV_ADMIN_PASSWORD = 'SnapshotDev.1234!';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<string>           $hooks
     * @param callable(string): void $onMessage
     * @param bool                   $databaseWasPulled Whether the local database was replaced in this run.
     *                                                  DB-only hooks are skipped when it was not.
     */
    public function run(array $hooks, callable $onMessage, bool $databaseWasPulled = true): void
    {
        foreach ($hooks as $hook) {
            match ($hook) {
                // Only additive changes (missing tables/fields) so the site boots with local code.
                // Type changes (*.change) are deliberately excluded: they can fail on legacy remote
                // data (e.g. a string value in a column the local schema defines as INT).
                'database_schema_update' => $databaseWasPulled
                    ? $this->console(['database:updateschema', '*.add'], $onMessage)
                    : $onMessage('database_schema_update: skipped — the database was not pulled, the local schema is unchanged.'),
                'cache_flush' => $this->console(['cache:flush'], $onMessage),
                'referenceindex' => $this->console(['referenceindex:update'], $onMessage),
                'reset_admin_password' => $databaseWasPulled
                    ? $this->resetAdminPasswords($onMessage)
                    : $onMessage('reset_admin_password: skipped — the database was not pulled, local backend logins are unchanged.'),
                'set_dev_context' => $onMessage('set_dev_context: skipped — the application context is set via the TYPO3_CONTEXT environment variable, not from here.'),
                default => $onMessage(sprintf('unknown hook "%s" — skipped', $hook)),
            };
        }
    }

    private function resetAdminPasswords(callable $onMessage): void
    {
        $hashedPassword = GeneralUtility::makeInstance(PasswordHashFactory::class)
            ->getDefaultHashInstance('BE')
            ->getHashedPassword(self::DEV_ADMIN_PASSWORD);

        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $affected = $connection->update('be_users', ['password' => $hashedPassword], ['admin' => 1]);

        $onMessage(sprintf('reset %d backend admin password(s) to "%s"', $affected, self::DEV_ADMIN_PASSWORD));
    }

    /**
     * @param list<string>           $arguments
     * @param callable(string): void $onMessage
     */
    private function console(array $arguments, callable $onMessage): void
    {
        $binary = Environment::getProjectPath() . '/vendor/bin/typo3';
        if (!is_file($binary)) {
            $onMessage(sprintf('typo3 console not found at "%s" — skipped %s', $binary, implode(' ', $arguments)));

            return;
        }

        $process = new Process([PHP_BINARY, $binary, ...$arguments]);
        $process->setTimeout(null);
        $process->run();

        $label = implode(' ', $arguments);
        if ($process->isSuccessful()) {
            $onMessage(sprintf('%s done', $label));

            return;
        }

        // typo3_console prints failures to stdout, so fall back to it when stderr is empty.
        $error = trim($process->getErrorOutput());
        if ($error === '') {
            $error = trim($process->getOutput());
        }
        $onMessage(sprintf('%s failed: %s', $label, $error));
    }
}
