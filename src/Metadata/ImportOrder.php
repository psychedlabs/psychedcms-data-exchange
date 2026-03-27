<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Metadata;

final readonly class ImportOrder
{
    /**
     * @param string[]                $orderedTypes       Type slugs in safe import order
     * @param array<string, string[]> $deferredRelations  typeName => field property names to defer
     */
    public function __construct(
        public array $orderedTypes,
        public array $deferredRelations,
    ) {
    }
}
