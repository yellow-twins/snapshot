<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend\Export;

use TYPO3\CMS\Core\Core\Environment;
use YellowTwins\Snapshot\Exception\SnapshotException;

/**
 * Creates a ZIP archive of the local fileadmin (excluding regenerable directories) inside the
 * non-public var/snapshot directory. Returns the archive path for the token service to secure.
 */
final class FileadminArchiveService
{
    /**
     * @param list<string> $excludes Path patterns; the first segment of each is treated as a
     *                               directory name to skip anywhere in the tree (e.g. _processed_)
     */
    public function archive(array $excludes): string
    {
        $source = rtrim(Environment::getPublicPath(), '/') . '/fileadmin';
        if (!is_dir($source)) {
            throw new SnapshotException(sprintf('Fileadmin directory "%s" does not exist.', $source), 1_752_901_100);
        }

        $directory = $this->storageDirectory();
        $target = $directory . '/tmp-' . bin2hex(random_bytes(8)) . '.zip';
        $excludeSegments = $this->excludeSegments($excludes);

        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new SnapshotException(sprintf('Could not create archive "%s".', $target), 1_752_901_101);
        }

        /** @var \SplFileInfo $file */
        foreach ($this->iterate($source) as $file) {
            $absolute = $file->getPathname();
            $relative = ltrim(substr($absolute, strlen($source)), '/');
            if ($this->isExcluded($relative, $excludeSegments)) {
                continue;
            }
            if ($file->isFile()) {
                $zip->addFile($absolute, 'fileadmin/' . $relative);
            }
        }

        $zip->close();

        return $target;
    }

    /**
     * @return \Iterator<\SplFileInfo>
     */
    private function iterate(string $source): \Iterator
    {
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
    }

    /**
     * @param list<string> $excludes
     * @return list<string>
     */
    private function excludeSegments(array $excludes): array
    {
        $segments = [];
        foreach ($excludes as $pattern) {
            $first = explode('/', trim($pattern, '/'))[0];
            if ($first !== '' && $first !== '*' && $first !== '**') {
                $segments[] = $first;
            }
        }

        return $segments;
    }

    /**
     * @param list<string> $excludeSegments
     */
    private function isExcluded(string $relativePath, array $excludeSegments): bool
    {
        foreach (explode('/', $relativePath) as $segment) {
            if (in_array($segment, $excludeSegments, true)) {
                return true;
            }
        }

        return false;
    }

    private function storageDirectory(): string
    {
        $directory = rtrim(Environment::getVarPath(), '/') . '/snapshot';
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new SnapshotException(sprintf('Could not create artifact storage directory "%s".', $directory), 1_752_901_102);
        }

        return $directory;
    }
}
