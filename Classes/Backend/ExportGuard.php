<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Runtime security gate for the backend export module (defence in depth). The module is
 * admin-only by registration; on top of that this guard enforces:
 *  - a kill-switch (disabled by default — must be explicitly enabled),
 *  - an optional IP allowlist (configured outside the backend, in the environment),
 *  - mandatory active two-factor authentication.
 */
final class ExportGuard
{
    public function evaluate(ServerRequestInterface $request): GuardResult
    {
        $problems = [];

        if (!$this->backendEnabled()) {
            $problems[] = 'The backend export module is disabled. Set SNAPSHOT_BACKEND_ENABLED=1 in the environment to enable it.';
        }
        if (!$this->ipAllowed($request)) {
            $problems[] = 'Your IP address is not in the allowlist (SNAPSHOT_ALLOWED_IPS).';
        }
        if ($this->mfaRequired() && !$this->mfaActive()) {
            $problems[] = 'Two-factor authentication must be active on your backend account before you can export.';
        }

        return new GuardResult($problems === [], $problems);
    }

    /**
     * Whether un-anonymized ("raw") database exports are permitted. Off by default; enabling it is
     * an environment decision (like the other controls), never a backend-editable setting, so a mere
     * backend admin can never export personal data without someone with server/.env access opting in.
     */
    public function allowsUnscrubbedExport(): bool
    {
        return getenv('SNAPSHOT_ALLOW_UNSCRUBBED') === '1';
    }

    private function backendEnabled(): bool
    {
        return getenv('SNAPSHOT_BACKEND_ENABLED') === '1';
    }

    private function mfaRequired(): bool
    {
        // Mandatory by default; opt out only in trusted/local contexts.
        return getenv('SNAPSHOT_REQUIRE_MFA') !== '0';
    }

    private function ipAllowed(ServerRequestInterface $request): bool
    {
        $allowlist = trim((string)getenv('SNAPSHOT_ALLOWED_IPS'));
        if ($allowlist === '') {
            // No allowlist configured: this control is not in use.
            return true;
        }

        $normalizedParams = $request->getAttribute('normalizedParams');
        $remoteAddress = $normalizedParams instanceof NormalizedParams ? $normalizedParams->getRemoteAddress() : '';
        if ($remoteAddress === '') {
            return false;
        }

        foreach (GeneralUtility::trimExplode(',', $allowlist, true) as $range) {
            if (GeneralUtility::cmpIP($remoteAddress, $range)) {
                return true;
            }
        }

        return false;
    }

    private function mfaActive(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        $mfa = $backendUser->user['mfa'] ?? null;
        if (!is_string($mfa) || $mfa === '') {
            return false;
        }

        $providers = json_decode($mfa, true);
        if (!is_array($providers)) {
            return false;
        }

        foreach ($providers as $provider) {
            if (is_array($provider) && ($provider['active'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
