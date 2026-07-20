<?php

declare(strict_types=1);

use YellowTwins\Snapshot\Backend\Controller\SnapshotModuleController;

/**
 * Backend module for Pillar A: download a snapshot of this environment. Registered under the
 * admin-only "tools" area; further gated at runtime by ExportGuard (kill-switch, IP allowlist, MFA).
 */
return [
    'tools_snapshot' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_toolsmaintenance'],
        'access' => 'admin',
        'path' => '/module/tools/snapshot',
        'iconIdentifier' => 'tx-snapshot-module',
        'labels' => 'LLL:EXT:snapshot/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => SnapshotModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
