<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Configuration;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use YellowTwins\Snapshot\Database\DatabaseConnection;
use YellowTwins\Snapshot\Exception\ConfigurationException;

/**
 * Loads and validates the project's .snapshot.yaml, resolving %env(NAME)% placeholders
 * against the process environment. Everything sensitive (hosts, users, paths) is expected
 * to live in .env and be referenced from the YAML.
 */
final class ConfigurationLoader
{
    private const FILENAME = '.snapshot.yaml';

    /**
     * @param callable(string): (string|false)|null $envResolver Override for env lookup (testing seam)
     */
    public function __construct(
        private readonly mixed $envResolver = null,
    ) {}

    public function configurationExists(string $projectRoot): bool
    {
        return is_file($this->filePath($projectRoot));
    }

    public function load(string $projectRoot): SnapshotConfiguration
    {
        $file = $this->filePath($projectRoot);
        if (!is_file($file)) {
            throw new ConfigurationException(
                sprintf('No %s found in "%s". Copy %s.dist to get started.', self::FILENAME, $projectRoot, self::FILENAME),
                1_752_900_010,
            );
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new ConfigurationException(sprintf('Unable to read "%s".', $file), 1_752_900_011);
        }

        try {
            $parsed = Yaml::parse($raw);
        } catch (ParseException $e) {
            throw new ConfigurationException(sprintf('Invalid YAML in "%s": %s', $file, $e->getMessage()), 1_752_900_012, $e);
        }

        if (!is_array($parsed)) {
            throw new ConfigurationException(sprintf('"%s" must contain a YAML mapping.', $file), 1_752_900_013);
        }

        /** @var array<string, mixed> $parsed */
        $parsed = $this->interpolate($parsed);

        return new SnapshotConfiguration(
            $this->buildEnvironments($parsed),
            $this->buildDefaults($parsed),
            $this->buildGuards($parsed),
        );
    }

    private function filePath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/' . self::FILENAME;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, EnvironmentConfig>
     */
    private function buildEnvironments(array $parsed): array
    {
        $environments = $parsed['environments'] ?? null;
        if (!is_array($environments) || $environments === []) {
            throw new ConfigurationException('At least one environment must be defined under "environments".', 1_752_900_020);
        }

        $result = [];
        foreach ($environments as $name => $definition) {
            if (!is_string($name)) {
                throw new ConfigurationException('Environment names must be strings.', 1_752_900_021);
            }
            if (!is_array($definition)) {
                throw new ConfigurationException(sprintf('Environment "%s" must be a mapping.', $name), 1_752_900_022);
            }

            $transport = $this->stringValue($definition, 'transport', $name, 'ssh');
            if ($transport !== 'ssh') {
                throw new ConfigurationException(
                    sprintf('Environment "%s": transport "%s" is not supported yet (only "ssh").', $name, $transport),
                    1_752_900_023,
                );
            }

            $fileSource = $this->stringValue($definition, 'file_source', $name, 'rsync');
            if ($fileSource !== 'rsync') {
                throw new ConfigurationException(
                    sprintf('Environment "%s": file_source "%s" is not supported yet (only "rsync").', $name, $fileSource),
                    1_752_900_024,
                );
            }

            $result[$name] = new EnvironmentConfig(
                name: $name,
                transport: $transport,
                host: $this->requireStringValue($definition, 'host', $name),
                user: $this->stringValue($definition, 'user', $name, ''),
                port: $this->intValue($definition, 'port', $name, 22),
                path: $this->requireStringValue($definition, 'path', $name),
                fileSource: $fileSource,
                php: $this->stringValue($definition, 'php', $name, 'php'),
                database: $this->buildDatabase($definition, $name),
            );
        }

        return $result;
    }

    /**
     * Parses the optional explicit "db" block of an environment.
     *
     * @param array<array-key, mixed> $definition
     */
    private function buildDatabase(array $definition, string $envName): ?DatabaseConnection
    {
        if (!array_key_exists('db', $definition)) {
            return null;
        }

        $db = $definition['db'];
        if (!is_array($db)) {
            throw new ConfigurationException(sprintf('Environment "%s": "db" must be a mapping.', $envName), 1_752_900_080);
        }

        $socket = $this->stringValue($db, 'socket', $envName, '');

        return new DatabaseConnection(
            host: $this->stringValue($db, 'host', $envName, '127.0.0.1'),
            port: $this->intValue($db, 'port', $envName, 3306),
            dbname: $this->requireStringValue($db, 'name', $envName),
            user: $this->requireStringValue($db, 'user', $envName),
            password: $this->stringValue($db, 'password', $envName, ''),
            unixSocket: $socket === '' ? null : $socket,
        );
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function buildDefaults(array $parsed): Defaults
    {
        $defaults = $parsed['defaults'] ?? [];
        if (!is_array($defaults)) {
            throw new ConfigurationException('"defaults" must be a mapping.', 1_752_900_030);
        }

        $scrub = $defaults['scrub'] ?? true;
        if (!is_bool($scrub)) {
            throw new ConfigurationException('"defaults.scrub" must be a boolean.', 1_752_900_031);
        }

        return new Defaults(
            scrub: $scrub,
            dbExclude: $this->stringList($defaults, 'db_exclude'),
            rsyncExcludes: $this->stringList($defaults, 'rsync_excludes'),
            postPull: $this->stringList($defaults, 'post_pull'),
        );
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function buildGuards(array $parsed): Guards
    {
        $guards = $parsed['guards'] ?? [];
        if (!is_array($guards)) {
            throw new ConfigurationException('"guards" must be a mapping.', 1_752_900_040);
        }

        $pushToLive = $guards['push_to_live'] ?? false;
        if (!is_bool($pushToLive)) {
            throw new ConfigurationException('"guards.push_to_live" must be a boolean.', 1_752_900_041);
        }

        return new Guards(pushToLive: $pushToLive);
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private function requireStringValue(array $definition, string $key, string $envName): string
    {
        if (!array_key_exists($key, $definition)) {
            throw new ConfigurationException(sprintf('Environment "%s" is missing required key "%s".', $envName, $key), 1_752_900_050);
        }

        return $this->stringValue($definition, $key, $envName, null);
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private function stringValue(array $definition, string $key, string $envName, ?string $default): string
    {
        if (!array_key_exists($key, $definition)) {
            if ($default === null) {
                throw new ConfigurationException(sprintf('Environment "%s" is missing required key "%s".', $envName, $key), 1_752_900_051);
            }

            return $default;
        }

        $value = $definition[$key];
        if (is_int($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            throw new ConfigurationException(sprintf('Environment "%s": key "%s" must be a string.', $envName, $key), 1_752_900_052);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private function intValue(array $definition, string $key, string $envName, int $default): int
    {
        if (!array_key_exists($key, $definition)) {
            return $default;
        }

        $value = $definition[$key];
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int)$value;
        }
        if (!is_int($value)) {
            throw new ConfigurationException(sprintf('Environment "%s": key "%s" must be an integer.', $envName, $key), 1_752_900_053);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $source
     * @return list<string>
     */
    private function stringList(array $source, string $key): array
    {
        $value = $source[$key] ?? [];
        if (!is_array($value)) {
            throw new ConfigurationException(sprintf('"%s" must be a list of strings.', $key), 1_752_900_060);
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new ConfigurationException(sprintf('"%s" must contain only strings.', $key), 1_752_900_061);
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Recursively replaces %env(NAME)% placeholders in string values.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function interpolate(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->interpolate($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->resolveEnvPlaceholders($value);
            }
        }

        return $data;
    }

    private function resolveEnvPlaceholders(string $value): string
    {
        return (string)preg_replace_callback(
            '/%env\(([A-Z0-9_]+)\)%/',
            function (array $matches): string {
                /** @var array{0: string, 1: string} $matches */
                $name = $matches[1];
                $resolved = $this->resolveEnv($name);
                if ($resolved === false) {
                    throw new ConfigurationException(
                        sprintf('Environment variable "%s" referenced in .snapshot.yaml is not set.', $name),
                        1_752_900_070,
                    );
                }

                return $resolved;
            },
            $value,
        );
    }

    private function resolveEnv(string $name): string|false
    {
        if ($this->envResolver !== null) {
            /** @var callable(string): (string|false) $resolver */
            $resolver = $this->envResolver;

            return $resolver($name);
        }

        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }

        if (isset($_ENV[$name]) && is_string($_ENV[$name])) {
            return $_ENV[$name];
        }

        return false;
    }
}
