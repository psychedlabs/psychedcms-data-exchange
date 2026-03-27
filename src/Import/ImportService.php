<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Import;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\DataExchange\ExchangeManifest;
use PsychedCms\DataExchange\Metadata\ContentTypeMetadataReader;
use PsychedCms\DataExchange\Metadata\DependencyResolver;
use PsychedCms\DataExchange\Worker\ContentWorker;
use PsychedCms\DataExchange\Worker\TaxonomyWorker;
use PsychedCms\DataExchange\Worker\UserWorker;
use Symfony\Component\Security\Core\User\UserInterface;

final class ImportService
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly ContentTypeMetadataReader $metadataReader,
        private readonly DependencyResolver $dependencyResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly ReferenceResolver $referenceResolver,
    ) {
    }

    /**
     * @param string[] $typeFilter       Empty = all types from manifest
     * @param string   $onMissingRef     warn | skip | fail
     *
     * @return array{imported: array<string, int>, warnings: list<string>}
     */
    public function import(
        string $inputDir,
        array $typeFilter = [],
        bool $dryRun = false,
        string $onMissingRef = 'warn',
    ): array {
        $manifestPath = $inputDir . '/manifest.json';

        if (!file_exists($manifestPath)) {
            throw new \RuntimeException('manifest.json not found in ' . $inputDir);
        }

        $manifest = ExchangeManifest::fromArray(
            json_decode(file_get_contents($manifestPath), true),
        );

        $schemas = $this->metadataReader->getSchemas();
        $importOrder = $this->dependencyResolver->resolve($schemas);
        $imported = [];

        $this->referenceResolver->clearCache();
        $this->referenceResolver->clearWarnings();

        // Import taxonomies first
        if ($this->shouldImport('taxonomies', $typeFilter, $manifest)) {
            $count = $this->importTaxonomies($inputDir, $dryRun);
            $imported['taxonomies'] = $count;
        }

        // Import users
        if ($this->shouldImport('users', $typeFilter, $manifest)) {
            $count = $this->importUsers($inputDir, $dryRun);
            $imported['users'] = $count;
        }

        // First pass: import content types in dependency order, skipping deferred fields
        foreach ($importOrder->orderedTypes as $typeName) {
            if (!$this->shouldImport($typeName, $typeFilter, $manifest)) {
                continue;
            }

            $filePath = $inputDir . '/' . $typeName . '.json';

            if (!file_exists($filePath)) {
                continue;
            }

            if (!isset($schemas[$typeName])) {
                continue;
            }

            $schema = $schemas[$typeName];
            $data = json_decode(file_get_contents($filePath), true);

            // Singleton: wrap in array
            if ($schema->singleton && isset($data['slug'])) {
                $data = [$data];
            }

            $deferredFields = $importOrder->deferredRelations[$typeName] ?? [];
            $worker = new ContentWorker($schema);
            $count = 0;

            foreach ($data as $index => $record) {
                if (!isset($record['slug'])) {
                    continue;
                }

                if (!$dryRun) {
                    $worker->import($record, $this->entityManager, $this->referenceResolver, $deferredFields);
                }

                ++$count;

                if (!$dryRun && $count % self::BATCH_SIZE === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    $this->referenceResolver->clearCache();
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }

            $imported[$typeName] = $count;
        }

        // Second pass: fill deferred relations
        if (!$dryRun) {
            $this->fillDeferredRelations($inputDir, $schemas, $importOrder);
        }

        $warnings = $this->referenceResolver->getWarnings();

        if ($onMissingRef === 'fail' && $warnings !== []) {
            throw new \RuntimeException(
                "Missing references detected:\n" . implode("\n", $warnings),
            );
        }

        return [
            'imported' => $imported,
            'warnings' => $warnings,
        ];
    }

    private function importTaxonomies(string $inputDir, bool $dryRun): int
    {
        $filePath = $inputDir . '/taxonomies.json';

        if (!file_exists($filePath)) {
            return 0;
        }

        $records = json_decode(file_get_contents($filePath), true);

        if ($dryRun) {
            return \count($records);
        }

        $worker = new TaxonomyWorker();

        return $worker->importAll($records, $this->entityManager, $this->referenceResolver);
    }

    private function importUsers(string $inputDir, bool $dryRun): int
    {
        $filePath = $inputDir . '/users.json';

        if (!file_exists($filePath)) {
            return 0;
        }

        $userClass = $this->findUserClass();

        if ($userClass === null) {
            return 0;
        }

        $records = json_decode(file_get_contents($filePath), true);

        if ($dryRun) {
            return \count($records);
        }

        $worker = new UserWorker($userClass);
        $count = 0;

        foreach ($records as $record) {
            $worker->import($record, $this->entityManager, $this->referenceResolver);
            ++$count;
        }

        $this->entityManager->flush();

        return $count;
    }

    /**
     * @param array<string, \PsychedCms\DataExchange\Metadata\ExportSchema> $schemas
     */
    private function fillDeferredRelations(
        string $inputDir,
        array $schemas,
        \PsychedCms\DataExchange\Metadata\ImportOrder $importOrder,
    ): void {
        foreach ($importOrder->deferredRelations as $typeName => $deferredFields) {
            if ($deferredFields === []) {
                continue;
            }

            $filePath = $inputDir . '/' . $typeName . '.json';

            if (!file_exists($filePath) || !isset($schemas[$typeName])) {
                continue;
            }

            $schema = $schemas[$typeName];
            $data = json_decode(file_get_contents($filePath), true);

            if ($schema->singleton && isset($data['slug'])) {
                $data = [$data];
            }

            $worker = new ContentWorker($schema);
            $count = 0;

            foreach ($data as $record) {
                if (!isset($record['slug'])) {
                    continue;
                }

                $worker->fillDeferred($record, $this->entityManager, $deferredFields);
                ++$count;

                if ($count % self::BATCH_SIZE === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    $this->referenceResolver->clearCache();
                }
            }

            $this->entityManager->flush();
        }
    }

    private function shouldImport(string $typeName, array $typeFilter, ExchangeManifest $manifest): bool
    {
        if ($typeFilter !== [] && !\in_array($typeName, $typeFilter, true)) {
            return false;
        }

        return \in_array($typeName, $manifest->entityTypes, true);
    }

    /**
     * @return class-string|null
     */
    private function findUserClass(): ?string
    {
        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $metadata) {
            $reflClass = new \ReflectionClass($metadata->getName());

            if ($reflClass->implementsInterface(UserInterface::class) && !$reflClass->isAbstract()) {
                return $metadata->getName();
            }
        }

        return null;
    }
}
