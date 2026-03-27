<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Export;

use League\Flysystem\FilesystemOperator;

final class MediaExporter
{
    private ?FilesystemOperator $filesystem = null;

    public function setFilesystem(FilesystemOperator $filesystem): void
    {
        $this->filesystem = $filesystem;
    }

    public function isAvailable(): bool
    {
        return $this->filesystem !== null;
    }

    /**
     * Copy media files from storage to a local directory.
     *
     * @param string[] $storagePaths
     */
    public function export(array $storagePaths, string $mediaDir): void
    {
        if ($this->filesystem === null) {
            return;
        }

        if (!is_dir($mediaDir)) {
            mkdir($mediaDir, 0o755, true);
        }

        $seen = [];

        foreach ($storagePaths as $path) {
            if (isset($seen[$path]) || $path === '') {
                continue;
            }

            $seen[$path] = true;
            $localPath = $mediaDir . '/' . $path;
            $localDir = \dirname($localPath);

            if (!is_dir($localDir)) {
                mkdir($localDir, 0o755, true);
            }

            try {
                if ($this->filesystem->fileExists($path)) {
                    $stream = $this->filesystem->readStream($path);
                    file_put_contents($localPath, $stream);

                    if (\is_resource($stream)) {
                        fclose($stream);
                    }
                }
            } catch (\Throwable) {
                // Skip files that can't be read
            }
        }
    }
}
