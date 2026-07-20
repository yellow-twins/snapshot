<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Writes an audit trail for backend export actions. Every prepare and download is recorded with
 * the acting backend user, so data exfiltration through the module can never go unnoticed.
 */
final class AuditLogger implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @param array<string, mixed> $context
     */
    public function record(string $action, array $context = []): void
    {
        $this->logger?->notice('Snapshot backend export: ' . $action, ['backendUser' => $this->currentUsername()] + $context);
    }

    private function currentUsername(): string
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication) {
            $username = $backendUser->user['username'] ?? null;
            if (is_string($username) && $username !== '') {
                return $username;
            }
        }

        return 'unknown';
    }
}
