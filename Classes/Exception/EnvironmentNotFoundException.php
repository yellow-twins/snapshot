<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Exception;

/**
 * Thrown when a requested environment is not defined in the configuration.
 */
final class EnvironmentNotFoundException extends SnapshotException {}
