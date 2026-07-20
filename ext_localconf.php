<?php

declare(strict_types=1);

use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;

defined('TYPO3') or die();

// Persist the backend export audit trail to a dedicated log file, so every prepare/download is
// recorded regardless of the global log level.
$GLOBALS['TYPO3_CONF_VARS']['LOG']['YellowTwins']['Snapshot']['Backend']['AuditLogger']['writerConfiguration'] = [
    LogLevel::NOTICE => [
        FileWriter::class => [
            'logFileInfix' => 'snapshot-audit',
        ],
    ],
];
