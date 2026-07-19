<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Configuration;

use YellowTwins\Snapshot\Exception\EnvironmentNotFoundException;

/**
 * Parsed representation of a project's .snapshot.yaml.
 */
final readonly class SnapshotConfiguration
{
    /**
     * @param array<string, EnvironmentConfig> $environments Keyed by environment name
     */
    public function __construct(
        public array $environments,
        public Defaults $defaults,
        public Guards $guards,
    ) {}

    public function getEnvironment(string $name): EnvironmentConfig
    {
        if (!isset($this->environments[$name])) {
            throw new EnvironmentNotFoundException(
                sprintf(
                    'Environment "%s" is not defined. Available: %s',
                    $name,
                    $this->environments === [] ? '(none)' : implode(', ', $this->getEnvironmentNames()),
                ),
                1_752_900_001,
            );
        }

        return $this->environments[$name];
    }

    /**
     * @return list<string>
     */
    public function getEnvironmentNames(): array
    {
        return array_keys($this->environments);
    }
}
