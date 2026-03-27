<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Export;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\DataExchange\ExchangeManifest;
use PsychedCms\DataExchange\Metadata\ContentTypeMetadataReader;
use PsychedCms\DataExchange\Metadata\DependencyResolver;
use PsychedCms\DataExchange\Worker\ContentWorker;
use PsychedCms\DataExchange\Worker\TaxonomyWorker;
use PsychedCms\DataExchange\Worker\UserWorker;
use PsychedCms\Taxonomy\Entity\Taxonomy;
use Symfony\Component\Security\Core\User\UserInterface;

final class ExportService
{
    public function __construct(
        private readonly ContentTypeMetadataReader $metadataReader,
        private readonly DependencyResolver $dependencyResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaExporter $mediaExporter,
    ) {
    }

    /**
     * @param string[] $typeFilter   Empty = all types
     * @param bool     $withMedia    Copy media files
     * @param bool     $pretty       Pretty-print JSON
     */
    public function export(
        string $outputDir,
        array $typeFilter = [],
        bool $withMedia = false,
        bool $pretty = false,
    ): ExchangeManifest {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0o755, true);
        }

        $schemas = $this->metadataReader->getSchemas();

        // Filter types and auto-include dependencies
        if ($typeFilter !== []) {
            $schemas = $this->filterWithDependencies($schemas, $typeFilter);
        }

        $importOrder = $this->dependencyResolver->resolve($schemas);
        $jsonFlags = $pretty ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES : \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;

        $entityTypes = [];
        $recordCounts = [];
        $storagePaths = [];

        // Export taxonomies (always first)
        $taxonomyCount = $this->exportTaxonomies($outputDir, $jsonFlags);

        if ($taxonomyCount > 0) {
            $entityTypes[] = 'taxonomies';
            $recordCounts['taxonomies'] = $taxonomyCount;
        }

        // Export users
        $userCount = $this->exportUsers($outputDir, $jsonFlags);

        if ($userCount > 0) {
            $entityTypes[] = 'users';
            $recordCounts['users'] = $userCount;
        }

        // Export content types in dependency order
        foreach ($importOrder->orderedTypes as $typeName) {
            if (!isset($schemas[$typeName])) {
                continue;
            }

            $schema = $schemas[$typeName];
            $worker = new ContentWorker($schema);

            $entities = $this->entityManager->getRepository($schema->entityClass)->findAll();
            $records = [];

            foreach ($entities as $entity) {
                $record = $worker->export($entity);
                $records[] = $record;

                if ($withMedia) {
                    $storagePaths = array_merge($storagePaths, $this->extractStoragePaths($record));
                }
            }

            if ($records === []) {
                continue;
            }

            $output = $schema->singleton ? $records[0] : $records;
            $filePath = $outputDir . '/' . $typeName . '.json';
            file_put_contents($filePath, json_encode($output, $jsonFlags));

            $entityTypes[] = $typeName;
            $recordCounts[$typeName] = \count($records);
        }

        // Export media files
        if ($withMedia && $storagePaths !== []) {
            $this->mediaExporter->export($storagePaths, $outputDir . '/media');
        }

        // Write manifest
        $manifest = ExchangeManifest::create($entityTypes, $recordCounts);
        file_put_contents(
            $outputDir . '/manifest.json',
            json_encode($manifest->toArray(), $jsonFlags),
        );

        return $manifest;
    }

    private function exportTaxonomies(string $outputDir, int $jsonFlags): int
    {
        $taxonomies = $this->entityManager->getRepository(Taxonomy::class)->findAll();

        if ($taxonomies === []) {
            return 0;
        }

        $worker = new TaxonomyWorker();
        $records = [];

        foreach ($taxonomies as $taxonomy) {
            $records[] = $worker->export($taxonomy);
        }

        file_put_contents(
            $outputDir . '/taxonomies.json',
            json_encode($records, $jsonFlags),
        );

        return \count($records);
    }

    private function exportUsers(string $outputDir, int $jsonFlags): int
    {
        $userClass = $this->findUserClass();

        if ($userClass === null) {
            return 0;
        }

        $users = $this->entityManager->getRepository($userClass)->findAll();

        if ($users === []) {
            return 0;
        }

        $worker = new UserWorker($userClass);
        $records = [];

        foreach ($users as $user) {
            $records[] = $worker->export($user);
        }

        file_put_contents(
            $outputDir . '/users.json',
            json_encode($records, $jsonFlags),
        );

        return \count($records);
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

    /**
     * Auto-include dependencies for filtered types.
     *
     * @param array<string, \PsychedCms\DataExchange\Metadata\ExportSchema> $schemas
     * @param string[] $typeFilter
     *
     * @return array<string, \PsychedCms\DataExchange\Metadata\ExportSchema>
     */
    private function filterWithDependencies(array $schemas, array $typeFilter): array
    {
        $needed = [];
        $queue = $typeFilter;

        while ($queue !== []) {
            $type = array_shift($queue);

            if (isset($needed[$type]) || !isset($schemas[$type])) {
                continue;
            }

            $needed[$type] = $schemas[$type];

            foreach ($schemas[$type]->dependencies as $dep) {
                if (!isset($needed[$dep])) {
                    $queue[] = $dep;
                }
            }
        }

        return $needed;
    }

    /**
     * Scan a record for storagePath values.
     *
     * @param array<string, mixed> $record
     *
     * @return string[]
     */
    private function extractStoragePaths(array $record): array
    {
        $paths = [];

        array_walk_recursive($record, function (mixed $value, string $key) use (&$paths): void {
            if ($key === 'storagePath' && \is_string($value) && $value !== '') {
                $paths[] = $value;
            }
        });

        return $paths;
    }
}
