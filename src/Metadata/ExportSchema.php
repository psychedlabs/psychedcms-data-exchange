<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Metadata;

final readonly class ExportSchema
{
    /**
     * @param string              $typeName         Content type slug (e.g. "bands")
     * @param class-string        $entityClass      Doctrine entity class
     * @param class-string|null   $translationClass Gedmo PersonalTranslation class
     * @param string[]            $locales          Available locales (first = default)
     * @param bool                $singleton        Single-record content type
     * @param FieldMapping[]      $fieldMappings    Configured field mappings
     * @param string[]            $dependencies     Type slugs this type depends on
     */
    public function __construct(
        public string $typeName,
        public string $entityClass,
        public ?string $translationClass,
        public array $locales,
        public bool $singleton,
        public array $fieldMappings,
        public array $dependencies,
    ) {
    }
}
