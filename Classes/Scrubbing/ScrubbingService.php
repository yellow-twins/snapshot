<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Scrubbing;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Anonymizes personal data in the freshly imported local database. Runs after import, so it
 * only ever touches the local copy. Ships sensible defaults for core tables; projects can add
 * or override rules per table via the "scrub_rules" block in .snapshot.yaml.
 */
final class ScrubbingService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ScrubExpressionBuilder $expressionBuilder,
    ) {}

    /**
     * @param array<string, ScrubRule>       $overrides  Rules from configuration, merged over the defaults
     * @param callable(string): void         $onMessage
     * @param callable(int, int): void|null  $onProgress Receives (tables done, total tables) as scrubbing advances
     */
    public function scrub(array $overrides, callable $onMessage, ?callable $onProgress = null): void
    {
        $rules = [...$this->defaultRules(), ...$overrides];
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
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
        $columns = array_keys($connection->createSchemaManager()->listTableColumns($table));
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->update($table);

        $applied = 0;
        foreach ($rule->set as $column => $template) {
            if (!in_array($column, $columns, true)) {
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
            'sys_log' => ScrubRule::truncate(),
        ];
    }
}
