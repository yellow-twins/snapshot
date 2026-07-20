<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Scrubbing;

use TYPO3\CMS\Core\Database\Connection;

/**
 * Anonymizes personal data in a database copy. The caller decides which connection to scrub, and
 * is responsible for never passing a connection to production data: the CLI pull scrubs the freshly
 * imported local copy, the backend export scrubs a throwaway temporary database. Ships sensible
 * defaults for core tables; extra rules can be merged over them per call.
 */
final class ScrubbingService
{
    /**
     * Bcrypt hash of the known development password (the plaintext lives in
     * PostPullHookRunner::DEV_ADMIN_PASSWORD, "SnapshotDev.1234!"). Backend accounts are reset to it
     * so a scrubbed copy stays loginable without shipping real password hashes. TYPO3 accepts the
     * bcrypt hash on verify regardless of the site's configured default hashing.
     */
    private const DEV_LOGIN_PASSWORD_HASH = '$2y$10$bFaYZLKE/yChCdJMXDSX0O/ON80msabvDOUiIO.KSCr6zNJOL08lm';

    public function __construct(
        private readonly ScrubExpressionBuilder $expressionBuilder,
    ) {}

    /**
     * @param Connection                     $connection The database to anonymize — MUST be a copy, never production
     * @param array<string, ScrubRule>       $overrides  Rules from configuration, merged over the defaults
     * @param callable(string): void         $onMessage
     * @param callable(int, int): void|null  $onProgress Receives (tables done, total tables) as scrubbing advances
     */
    public function scrub(Connection $connection, array $overrides, callable $onMessage, ?callable $onProgress = null): void
    {
        $rules = [...$this->defaultRules(), ...$overrides];
        $schemaManager = $connection->createSchemaManager();
        $existingTables = $schemaManager->listTableNames();

        // Only rules whose table exists locally are real work — that count drives the progress bar.
        $applicable = [];
        foreach ($rules as $table => $rule) {
            if (in_array($table, $existingTables, true)) {
                $applicable[$table] = $rule;
            }
        }

        $total = count($applicable);
        $done = 0;
        if ($onProgress !== null) {
            $onProgress($done, $total);
        }

        foreach ($applicable as $table => $rule) {
            if ($rule->truncate) {
                $connection->truncate($table);
                $onMessage(sprintf('truncated %s', $table));
            } else {
                $this->anonymize($connection, $table, $rule, $onMessage);
            }

            ++$done;
            if ($onProgress !== null) {
                $onProgress($done, $total);
            }
        }
    }

    /**
     * @param callable(string): void $onMessage
     */
    private function anonymize(Connection $connection, string $table, ScrubRule $rule, callable $onMessage): void
    {
        // Doctrine lowercases the column-name keys, so compare case-insensitively; otherwise a
        // camelCase column (e.g. be_users.realName) would be treated as missing and skipped.
        $columns = array_map('strtolower', array_keys($connection->createSchemaManager()->listTableColumns($table)));
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->update($table);

        $applied = 0;
        foreach ($rule->set as $column => $template) {
            if (!in_array(strtolower($column), $columns, true)) {
                continue;
            }
            $expression = $this->expressionBuilder->build(
                $template,
                static fn(string $value): string => $connection->quote($value),
                static fn(string $identifier): string => $connection->quoteIdentifier($identifier),
            );
            $queryBuilder->set($column, $expression, false);
            ++$applied;
        }

        if ($applied === 0) {
            return;
        }

        $affected = $queryBuilder->executeStatement();
        $onMessage(sprintf('anonymized %s (%d rows, %d columns)', $table, $affected, $applied));
    }

    /**
     * @return array<string, ScrubRule>
     */
    private function defaultRules(): array
    {
        return [
            'fe_users' => ScrubRule::set([
                'username' => 'user{uid}',
                'password' => '',
                'name' => 'Anonymous User',
                'first_name' => 'Anonymous',
                'last_name' => 'User',
                'middle_name' => '',
                'email' => 'user{uid}@example.invalid',
                'title' => '',
                'address' => '',
                'telephone' => '',
                'fax' => '',
                'city' => '',
                'zip' => '',
                'company' => '',
                'www' => '',
            ]),
            'be_users' => ScrubRule::set([
                // Anonymize backend account PII and replace every password hash with the known dev
                // login, so a scrubbed copy carries no real names, e-mails or credential hashes yet
                // stays loginable (username + the dev password). Non-existent columns are skipped.
                'realName' => 'Anonymous User',
                'email' => 'user{uid}@example.invalid',
                'password' => self::DEV_LOGIN_PASSWORD_HASH,
            ]),
            'sys_log' => ScrubRule::truncate(),
        ];
    }
}
