<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\DataExchange\Export\ExportService;
use PsychedCms\DataExchange\Import\ImportService;
use PsychedCms\DataExchange\Metadata\ContentTypeMetadataReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/system/data-exchange')]
#[IsGranted('ROLE_ADMIN')]
class DataExchangeController extends AbstractController
{
    private readonly string $tmpDir;

    public function __construct(
        private readonly ContentTypeMetadataReader $metadataReader,
        private readonly ExportService $exportService,
        private readonly ImportService $importService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->tmpDir = sys_get_temp_dir() . '/psychedcms-data-exchange';
    }

    #[Route('/types', name: 'psychedcms_data_exchange_types', methods: ['GET'])]
    public function types(): JsonResponse
    {
        $schemas = $this->metadataReader->getSchemas();
        $types = [];

        foreach ($schemas as $typeName => $schema) {
            $count = $this->entityManager->getRepository($schema->entityClass)->count([]);

            $types[] = [
                'name' => $typeName,
                'entityClass' => $schema->entityClass,
                'singleton' => $schema->singleton,
                'recordCount' => $count,
            ];
        }

        return $this->json($types);
    }

    #[Route('/export', name: 'psychedcms_data_exchange_export', methods: ['POST'])]
    public function export(Request $request): BinaryFileResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            throw $this->createNotFoundException('Invalid JSON');
        }

        $types = $data['types'] ?? [];
        $pretty = (bool) ($data['pretty'] ?? false);
        $withMedia = (bool) ($data['withMedia'] ?? false);

        $exportDir = $this->tmpDir . '/export-' . Uuid::v4();
        mkdir($exportDir, 0o755, true);

        try {
            $this->exportService->export($exportDir, $types, $withMedia, $pretty);

            $zipPath = $exportDir . '.zip';
            $this->createZip($exportDir, $zipPath);

            $response = new BinaryFileResponse($zipPath);
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'data-exchange-' . date('Y-m-d_H-i-s') . '.zip',
            );
            $response->deleteFileAfterSend(true);

            return $response;
        } finally {
            $this->removeDirectory($exportDir);
        }
    }

    #[Route('/import/upload', name: 'psychedcms_data_exchange_import_upload', methods: ['POST'])]
    public function uploadForImport(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null || !$file->isValid()) {
            return $this->json(['error' => 'No valid file uploaded'], 400);
        }

        $tempId = Uuid::v4()->toRfc4122();
        $importDir = $this->tmpDir . '/import-' . $tempId;
        mkdir($importDir, 0o755, true);

        $zip = new \ZipArchive();

        if ($zip->open($file->getPathname()) !== true) {
            $this->removeDirectory($importDir);

            return $this->json(['error' => 'Invalid ZIP file'], 400);
        }

        $zip->extractTo($importDir);
        $zip->close();

        $manifestPath = $importDir . '/manifest.json';

        if (!file_exists($manifestPath)) {
            $this->removeDirectory($importDir);

            return $this->json(['error' => 'No manifest.json found in archive'], 400);
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        return $this->json([
            'tempId' => $tempId,
            'manifest' => $manifest,
        ]);
    }

    #[Route('/import/execute', name: 'psychedcms_data_exchange_import_execute', methods: ['POST'])]
    public function executeImport(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!\is_array($data) || !isset($data['tempId'])) {
            return $this->json(['error' => 'Invalid request'], 400);
        }

        $tempId = $data['tempId'];

        if (!preg_match('/^[0-9a-f\-]{36}$/', $tempId)) {
            return $this->json(['error' => 'Invalid tempId'], 400);
        }

        $importDir = $this->tmpDir . '/import-' . $tempId;

        if (!is_dir($importDir)) {
            return $this->json(['error' => 'Import session not found or expired'], 404);
        }

        $types = $data['types'] ?? [];
        $dryRun = (bool) ($data['dryRun'] ?? false);
        $onMissingRef = \in_array($data['onMissingRef'] ?? '', ['warn', 'skip', 'fail'], true)
            ? $data['onMissingRef']
            : 'warn';

        try {
            $results = $this->importService->import($importDir, $types, $dryRun, $onMissingRef);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } finally {
            if (!$dryRun) {
                $this->removeDirectory($importDir);
            }
        }

        return $this->json($results);
    }

    private function createZip(string $sourceDir, string $zipPath): void
    {
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, \strlen($sourceDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($dir);
    }
}
