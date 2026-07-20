<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend\Export;

use YellowTwins\Snapshot\Exception\SnapshotException;

/**
 * Thrown when the anonymized database export cannot be produced — for example because the database
 * user lacks the CREATE privilege needed for the temporary working database, or the platform is not
 * MySQL/MariaDB. The message is safe to show to the backend user; the module then falls back to the
 * fileadmin export.
 */
final class DatabaseExportException extends SnapshotException {}
