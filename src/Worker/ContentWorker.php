<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Worker;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\DataExchange\Import\ReferenceResolver;
use PsychedCms\DataExchange\Metadata\ExportSchema;
use PsychedCms\DataExchange\Metadata\FieldMapping;

final class ContentWorker
{
    public function __construct(
        private readonly ExportSchema $schema,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(object $entity): array
    {
        $data = ['slug' => $entity->getSlug()];

        foreach ($this->schema->fieldMappings as $mapping) {
            $raw = $mapping->readFrom($entity);
            $data[$mapping->exportName] = $mapping->handler->extract($raw);
        }

        if ($this->schema->translationClass !== null) {
            $translations = $this->exportTranslations($entity);

            if ($translations !== []) {
                $data['_translations'] = $translations;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed>   $data
     * @param string[]               $skipFields  Property names to skip (deferred)
     */
    public function import(
        array $data,
        EntityManagerInterface $em,
        ReferenceResolver $resolver,
        array $skipFields = [],
    ): object {
        $entity = $this->findOrCreate($em, $data['slug']);

        foreach ($this->schema->fieldMappings as $mapping) {
            if (\in_array($mapping->propertyName, $skipFields, true)) {
                continue;
            }

            if (!\array_key_exists($mapping->exportName, $data)) {
                continue;
            }

            $transformed = $mapping->handler->hydrate($data[$mapping->exportName]);
            $mapping->writeTo($entity, $transformed);
        }

        if ($this->schema->translationClass !== null && isset($data['_translations'])) {
            $this->importTranslations($entity, $data['_translations']);
        }

        // Register in resolver for future reference resolution
        $resolver->register($this->schema->entityClass, $data['slug'], $entity);

        $em->persist($entity);

        return $entity;
    }

    /**
     * Fill deferred relation fields (second pass).
     *
     * @param array<string, mixed> $data
     * @param string[]             $deferredFields Property names to fill
     */
    public function fillDeferred(
        array $data,
        EntityManagerInterface $em,
        array $deferredFields,
    ): void {
        $entity = $em->getRepository($this->schema->entityClass)->findOneBy(['slug' => $data['slug']]);

        if ($entity === null) {
            return;
        }

        foreach ($this->schema->fieldMappings as $mapping) {
            if (!\in_array($mapping->propertyName, $deferredFields, true)) {
                continue;
            }

            if (!\array_key_exists($mapping->exportName, $data)) {
                continue;
            }

            $transformed = $mapping->handler->hydrate($data[$mapping->exportName]);
            $mapping->writeTo($entity, $transformed);
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function exportTranslations(object $entity): array
    {
        $translations = [];
        $defaultLocale = $this->schema->locales[0] ?? 'fr';

        // Build property → exportName map for translatable fields
        $translatableMap = [];

        foreach ($this->schema->fieldMappings as $mapping) {
            if ($mapping->translatable) {
                $translatableMap[$mapping->propertyName] = $mapping->exportName;
            }
        }

        if ($translatableMap === []) {
            return [];
        }

        if (!method_exists($entity, 'getTranslations')) {
            return [];
        }

        foreach ($entity->getTranslations() as $translation) {
            $locale = $translation->getLocale();

            if ($locale === $defaultLocale) {
                continue;
            }

            $field = $translation->getField();

            if (isset($translatableMap[$field])) {
                $exportName = $translatableMap[$field];
                $translations[$locale][$exportName] = $translation->getContent();
            }
        }

        return $translations;
    }

    /**
     * @param array<string, array<string, string>> $translationsData
     */
    private function importTranslations(object $entity, array $translationsData): void
    {
        $translationClass = $this->schema->translationClass;

        if ($translationClass === null || !method_exists($entity, 'getTranslations')) {
            return;
        }

        // Build exportName → propertyName map for translatable fields
        $exportToProperty = [];

        foreach ($this->schema->fieldMappings as $mapping) {
            if ($mapping->translatable) {
                $exportToProperty[$mapping->exportName] = $mapping->propertyName;
            }
        }

        // Index existing translations by locale+field for upsert
        $existingMap = [];

        foreach ($entity->getTranslations() as $translation) {
            $key = $translation->getLocale() . ':' . $translation->getField();
            $existingMap[$key] = $translation;
        }

        // Upsert translations
        foreach ($translationsData as $locale => $fields) {
            foreach ($fields as $exportName => $value) {
                $propertyName = $exportToProperty[$exportName] ?? null;

                if ($propertyName === null) {
                    continue;
                }

                $key = $locale . ':' . $propertyName;

                if (isset($existingMap[$key])) {
                    // Update existing translation
                    $existingMap[$key]->setContent($value);
                } else {
                    // Create new translation
                    /** @var object $translation */
                    $translation = new $translationClass();
                    $translation->setLocale($locale);
                    $translation->setField($propertyName);
                    $translation->setContent($value);

                    if (method_exists($entity, 'addTranslation')) {
                        $entity->addTranslation($translation);
                    }
                }
            }
        }
    }

    private function findOrCreate(EntityManagerInterface $em, string $slug): object
    {
        $entity = $em->getRepository($this->schema->entityClass)->findOneBy(['slug' => $slug]);

        if ($entity !== null) {
            return $entity;
        }

        $entityClass = $this->schema->entityClass;
        $entity = new $entityClass();
        $entity->setSlug($slug);

        return $entity;
    }
}
