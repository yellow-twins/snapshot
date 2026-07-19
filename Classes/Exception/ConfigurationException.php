<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Exception;

/**
 * Thrown when the .snapshot.yaml configuration is missing, malformed or incomplete.
 */
final class ConfigurationException extends SnapshotException {}
