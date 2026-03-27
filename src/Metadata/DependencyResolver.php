<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Metadata;

final class DependencyResolver
{
    /**
     * Compute a safe import order using Kahn's topological sort.
     * Detects cycles and marks relations that must be deferred to a second pass.
     *
     * @param array<string, ExportSchema> $schemas typeName => schema
     */
    public function resolve(array $schemas): ImportOrder
    {
        // Build adjacency list and in-degree map
        $inDegree = [];
        $graph = [];
        $typeNames = array_keys($schemas);

        foreach ($typeNames as $type) {
            $inDegree[$type] = 0;
            $graph[$type] = [];
        }

        foreach ($schemas as $typeName => $schema) {
            foreach ($schema->dependencies as $dep) {
                if (!isset($inDegree[$dep])) {
                    continue; // dependency not in the export set
                }

                // $typeName depends on $dep → $dep must come first
                // edge: dep → typeName
                $graph[$dep][] = $typeName;
                ++$inDegree[$typeName];
            }
        }

        // Kahn's algorithm
        $queue = [];
        foreach ($inDegree as $type => $degree) {
            if ($degree === 0) {
                $queue[] = $type;
            }
        }

        $sorted = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted[] = $current;

            foreach ($graph[$current] as $neighbor) {
                --$inDegree[$neighbor];

                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        // If all types are sorted, no cycles
        if (\count($sorted) === \count($typeNames)) {
            return new ImportOrder(orderedTypes: $sorted, deferredRelations: []);
        }

        // Cycle detected: remaining nodes form cycles
        $cycleNodes = array_diff($typeNames, $sorted);
        $deferredRelations = $this->computeDeferredRelations($schemas, $cycleNodes);

        // Re-sort without deferred edges
        $orderedTypes = $this->resortWithDeferrals($schemas, $deferredRelations);

        return new ImportOrder(
            orderedTypes: $orderedTypes,
            deferredRelations: $deferredRelations,
        );
    }

    /**
     * For cycle nodes, find mutual dependencies and mark them as deferred.
     *
     * @param array<string, ExportSchema> $schemas
     * @param string[]                    $cycleNodes
     *
     * @return array<string, string[]> typeName => deferred property names
     */
    private function computeDeferredRelations(array $schemas, array $cycleNodes): array
    {
        $deferred = [];

        foreach ($cycleNodes as $typeName) {
            $schema = $schemas[$typeName];

            foreach ($schema->dependencies as $dep) {
                if (\in_array($dep, $cycleNodes, true)) {
                    // Find the field mappings that point to this dependency
                    foreach ($schema->fieldMappings as $mapping) {
                        if ($this->mappingTargets($mapping, $dep, $schemas)) {
                            $deferred[$typeName][] = $mapping->propertyName;
                        }
                    }
                }
            }
        }

        return $deferred;
    }

    /**
     * Check if a field mapping targets a given content type.
     *
     * @param array<string, ExportSchema> $schemas
     */
    private function mappingTargets(FieldMapping $mapping, string $targetType, array $schemas): bool
    {
        $handler = $mapping->handler;

        // Unwrap CollectionFieldHandler
        if ($handler instanceof \PsychedCms\DataExchange\FieldHandler\CollectionFieldHandler) {
            $refProp = new \ReflectionProperty($handler, 'itemHandler');
            $handler = $refProp->getValue($handler);
        }

        if ($handler instanceof \PsychedCms\DataExchange\FieldHandler\RelationFieldHandler) {
            $refProp = new \ReflectionProperty($handler, 'targetEntityClass');
            $targetClass = $refProp->getValue($handler);

            foreach ($schemas as $typeName => $schema) {
                if ($typeName === $targetType && $schema->entityClass === $targetClass) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Re-sort after removing deferred edges.
     *
     * @param array<string, ExportSchema> $schemas
     * @param array<string, string[]>     $deferredRelations
     *
     * @return string[]
     */
    private function resortWithDeferrals(array $schemas, array $deferredRelations): array
    {
        $typeNames = array_keys($schemas);
        $inDegree = array_fill_keys($typeNames, 0);
        $graph = array_fill_keys($typeNames, []);

        foreach ($schemas as $typeName => $schema) {
            $deferredFields = $deferredRelations[$typeName] ?? [];

            foreach ($schema->dependencies as $dep) {
                if (!isset($inDegree[$dep])) {
                    continue;
                }

                // Skip deferred dependencies
                $isDep = false;

                foreach ($schema->fieldMappings as $mapping) {
                    if (\in_array($mapping->propertyName, $deferredFields, true)) {
                        continue;
                    }

                    if ($this->mappingTargets($mapping, $dep, $schemas)) {
                        $isDep = true;
                        break;
                    }
                }

                if ($isDep) {
                    $graph[$dep][] = $typeName;
                    ++$inDegree[$typeName];
                }
            }
        }

        // Kahn's again
        $queue = [];
        foreach ($inDegree as $type => $degree) {
            if ($degree === 0) {
                $queue[] = $type;
            }
        }

        $sorted = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted[] = $current;

            foreach ($graph[$current] as $neighbor) {
                --$inDegree[$neighbor];

                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        // Append any remaining (shouldn't happen after deferrals, but safety net)
        foreach ($typeNames as $type) {
            if (!\in_array($type, $sorted, true)) {
                $sorted[] = $type;
            }
        }

        return $sorted;
    }
}
