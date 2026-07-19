<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Scrubbing;

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
     * @param array<string, ScrubRule> $overrides Rules from configuration, merged over the defaults
     * @param callable(string): void   $onMessage
     */
    public function scrub(array $overrides, callable $onMessage): void
    {
        $rules = [...$this->defaultRules(), ...$overrides];
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $schemaManager = $connection->createSchemaManager();
        $existingTables = $schemaManager->listTableNames();

        foreach ($rules as $table => $rule) {
            if (!in_array($table, $existingTables, true)) {
                continue;
            }

            if ($rule->truncate) {
                $connection->truncate($table);
                $onMessage(sprintf('truncated %s', $table));

                continue;
            }

            $columns = array_keys($schemaManager->listTableColumns($table));
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
                continue;
            }

            $affected = $queryBuilder->executeStatement();
            $onMessage(sprintf('anonymized %s (%d rows, %d columns)', $table, $affected, $applied));
        }
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
