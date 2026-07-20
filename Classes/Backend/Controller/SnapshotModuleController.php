<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use YellowTwins\Snapshot\Backend\AuditLogger;
use YellowTwins\Snapshot\Backend\Download\DownloadTokenService;
use YellowTwins\Snapshot\Backend\Export\FileadminArchiveService;
use YellowTwins\Snapshot\Backend\ExportGuard;
use YellowTwins\Snapshot\Util\ByteFormatter;

/**
 * Backend module controller for Pillar A (download a snapshot of this environment).
 *
 * Actions: index (source selection), prepare (server-side export + single-use token),
 * download (atomic single-use, streamed, audited). Every action re-checks the security gate.
 */
final class SnapshotModuleController
{
    private const TOKEN_TTL_SECONDS = 900;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ExportGuard $exportGuard,
        private readonly FileadminArchiveService $fileadminArchiveService,
        private readonly DownloadTokenService $downloadTokenService,
        private readonly AuditLogger $auditLogger,
        private readonly ByteFormatter $byteFormatter,
        private readonly UriBuilder $uriBuilder,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $this->exportGuard->evaluate($request);
        if (!$guard->allowed) {
            return $this->render($request, ['phase' => 'blocked', 'problems' => $guard->problems]);
        }

        $this->downloadTokenService->purge(time());
        $action = $this->stringParam($request->getQueryParams(), 'action', 'index');

        return match ($action) {
            'prepare' => $this->prepare($request),
            'download' => $this->download($request),
            default => $this->render($request, ['phase' => 'idle', 'problems' => []]),
        };
    }

    private function prepare(ServerRequestInterface $request): ResponseInterface
    {
        $sources = $this->requestedSources($request);
        if ($sources === []) {
            return $this->render($request, ['phase' => 'idle', 'problems' => []]);
        }

        $now = time();
        $artifacts = [];
        $notes = [];

        if (in_array('files', $sources, true)) {
            $archivePath = $this->fileadminArchiveService->archive(['_processed_', '_temp_']);
            $token = $this->downloadTokenService->issue($archivePath, 'fileadmin.zip', self::TOKEN_TTL_SECONDS, $now);
            $artifacts[] = [
                'name' => 'Fileadmin archive',
                'size' => $this->byteFormatter->format($token->byteSize),
                // Note: the download-token parameter must NOT be named "token" — that collides
                // with the backend route's CSRF token parameter.
                'url' => (string)$this->uriBuilder->buildUriFromRoute('tools_snapshot', ['action' => 'download', 'dl' => $token->token]),
            ];
        }
        if (in_array('db', $sources, true)) {
            $notes[] = 'Database export is anonymized server-side and is being wired in the next release; your fileadmin archive is ready below.';
        }

        $this->auditLogger->record('prepared', ['sources' => $sources, 'artifacts' => count($artifacts)]);

        // Database-only for now produces no artifact (DB export lands in the next release); bounce
        // back to the selection screen with a clear explanation instead of silently doing nothing.
        $flash = null;
        if ($artifacts === []) {
            $flash = 'Database export (anonymized server-side) is being wired in the next release. Select Fileadmin to download a snapshot now.';
        }

        return $this->render($request, [
            'phase' => $artifacts === [] ? 'idle' : 'ready',
            'problems' => [],
            'artifacts' => $artifacts,
            'notes' => $notes,
            'flash' => $flash,
            'expirySeconds' => self::TOKEN_TTL_SECONDS,
        ]);
    }

    private function download(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->stringParam($request->getQueryParams(), 'dl', '');
        $artifact = $this->downloadTokenService->consume($token, time());
        if ($artifact === null) {
            $this->auditLogger->record('download-rejected');

            return $this->render($request, ['phase' => 'idle', 'problems' => [], 'flash' => 'That download link has expired or was already used. Prepare a new snapshot.']);
        }

        $this->auditLogger->record('downloaded', ['name' => $artifact->downloadName, 'bytes' => $artifact->byteSize]);

        $filePath = $artifact->filePath;
        register_shutdown_function(static function () use ($filePath): void {
            @unlink($filePath);
        });

        return new Response(
            new Stream($filePath, 'rb'),
            200,
            [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $artifact->downloadName . '"',
                'Content-Length' => (string)$artifact->byteSize,
                'Cache-Control' => 'no-store',
            ],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(ServerRequestInterface $request, array $data): ResponseInterface
    {
        $phase = $data['phase'] ?? 'idle';
        if ($phase === 'idle' || $phase === 'ready') {
            GeneralUtility::makeInstance(PageRenderer::class)
                ->loadJavaScriptModule('@yellow-twins/snapshot/snapshot-module.js');
        }

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Snapshot');
        $view->assignMultiple($data + ['prepareUrl' => (string)$this->uriBuilder->buildUriFromRoute('tools_snapshot', ['action' => 'prepare'])]);

        return $view->renderResponse('SnapshotModule');
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function stringParam(array $params, string $key, string $default): string
    {
        $value = $params[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private function requestedSources(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $sources = is_array($body) ? ($body['sources'] ?? []) : [];
        if (!is_array($sources)) {
            return [];
        }

        $result = [];
        foreach ($sources as $source) {
            if ($source === 'db' || $source === 'files') {
                $result[] = $source;
            }
        }

        return $result;
    }
}
