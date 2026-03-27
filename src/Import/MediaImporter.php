<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Import;

use League\Flysystem\FilesystemOperator;

final class MediaImporter
{
    private ?FilesystemOperator $filesystem = null;

    public function setFilesystem(FilesystemOperator $filesystem): void
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Upload media files from local directory to storage.
     *
     * @return int Number of files imported
     */
    public function import(string $mediaDir): int
    {
        if ($this->filesystem === null) {
            return 0;
        }

        if (!is_dir($mediaDir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($mediaDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relativePath = str_replace($mediaDir . '/', '', $file->getPathname());

            try {
                $stream = fopen($file->getPathname(), 'rb');

                if ($stream !== false) {
                    $this->filesystem->writeStream($relativePath, $stream);

                    if (\is_resource($stream)) {
                        fclose($stream);
                    }

                    ++$count;
                }
            } catch (\Throwable) {
                // Skip files that can't be written
            }
        }

        return $count;
    }
}
