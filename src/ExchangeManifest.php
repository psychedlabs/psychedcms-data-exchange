<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange;

final readonly class ExchangeManifest
{
    /**
     * @param string[]            $entityTypes   Ordered list of type slugs included
     * @param array<string, int>  $recordCounts  typeName => number of records
     */
    public function __construct(
        public string $formatVersion,
        public string $exportedAt,
        public string $source,
        public array $entityTypes,
        public array $recordCounts,
    ) {
    }

    /**
     * @param array<string, int> $recordCounts
     */
    public static function create(array $entityTypes, array $recordCounts): self
    {
        return new self(
            formatVersion: '1.0',
            exportedAt: (new \DateTimeImmutable())->format('c'),
            source: 'psychedcms',
            entityTypes: $entityTypes,
            recordCounts: $recordCounts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format_version' => $this->formatVersion,
            'exported_at' => $this->exportedAt,
            'source' => $this->source,
            'entity_types' => $this->entityTypes,
            'record_counts' => $this->recordCounts,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            formatVersion: $data['format_version'] ?? '1.0',
            exportedAt: $data['exported_at'] ?? '',
            source: $data['source'] ?? 'psychedcms',
            entityTypes: $data['entity_types'] ?? [],
            recordCounts: $data['record_counts'] ?? [],
        );
    }
}
