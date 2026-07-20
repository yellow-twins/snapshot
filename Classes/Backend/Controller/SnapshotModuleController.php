<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use YellowTwins\Snapshot\Backend\ExportGuard;

/**
 * Backend module controller for Pillar A (download a snapshot of this environment).
 *
 * This slice renders the entry screen and the security gate. The prepare/export/download flow
 * with single-use expiring tokens is wired in a follow-up.
 */
final class SnapshotModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ExportGuard $exportGuard,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $this->exportGuard->evaluate($request);

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Snapshot');
        $view->assignMultiple([
            'allowed' => $guard->allowed,
            'problems' => $guard->problems,
        ]);

        return $view->renderResponse('SnapshotModule');
    }
}
