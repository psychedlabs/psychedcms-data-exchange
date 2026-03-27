<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Mapping\Annotation as Gedmo;
use PsychedCms\Core\Attribute\ContentType;
use PsychedCms\Core\Attribute\Field\FieldAttribute;
use PsychedCms\Core\Attribute\Field\FieldAttributeInterface;
use PsychedCms\Core\Attribute\Field\RelationField;
use PsychedCms\DataExchange\FieldHandler\CollectionFieldHandler;
use PsychedCms\DataExchange\FieldHandler\FieldHandler;
use PsychedCms\DataExchange\FieldHandler\MediaFieldHandler;
use PsychedCms\DataExchange\FieldHandler\RelationFieldHandler;
use PsychedCms\DataExchange\FieldHandler\ScalarFieldHandler;
use PsychedCms\DataExchange\FieldHandler\TaxonomyFieldHandler;
use PsychedCms\DataExchange\Import\ReferenceResolver;
use PsychedCms\Media\Attribute\ImageField;
use PsychedCms\Media\Attribute\ImageListField;
use PsychedCms\Taxonomy\Attribute\EntityTaxonomyField;
use PsychedCms\Taxonomy\Attribute\TaxonomyField;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\SerializedName;

final class ContentTypeMetadataReader
{
    /** @var array<string, ExportSchema> typeName => schema */
    private ?array $schemasCache = null;

    /** @var array<string, string> entityClass => typeName */
    private array $classToTypeMap = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReferenceResolver $referenceResolver,
    ) {
    }

    /**
     * @return array<string, ExportSchema> typeName => schema
     */
    public function getSchemas(): array
    {
        if ($this->schemasCache !== null) {
            return $this->schemasCache;
        }

        $this->schemasCache = [];
        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        // First pass: build class → type map
        foreach ($allMetadata as $metadata) {
            $className = $metadata->getName();
            $reflClass = new \ReflectionClass($className);
            $contentTypeAttrs = $reflClass->getAttributes(ContentType::class);

            if ($contentTypeAttrs === []) {
                continue;
            }

            $contentType = $contentTypeAttrs[0]->newInstance();
            $schema = $contentType->toSchemaArray($className);
            $this->classToTypeMap[$className] = $schema['slug'];
        }

        // Second pass: build full schemas with dependencies
        foreach ($allMetadata as $metadata) {
            $className = $metadata->getName();

            if (!isset($this->classToTypeMap[$className])) {
                continue;
            }

            $schema = $this->buildSchema($className);

            if ($schema !== null) {
                $this->schemasCache[$schema->typeName] = $schema;
            }
        }

        return $this->schemasCache;
    }

    public function getSchemaForType(string $typeName): ?ExportSchema
    {
        return $this->getSchemas()[$typeName] ?? null;
    }

    /**
     * @return array<string, string> entityClass => typeName
     */
    public function getClassToTypeMap(): array
    {
        $this->getSchemas();

        return $this->classToTypeMap;
    }

    private function buildSchema(string $entityClass): ?ExportSchema
    {
        $reflClass = new \ReflectionClass($entityClass);
        $contentTypeAttrs = $reflClass->getAttributes(ContentType::class);

        if ($contentTypeAttrs === []) {
            return null;
        }

        $contentType = $contentTypeAttrs[0]->newInstance();
        $ctSchema = $contentType->toSchemaArray($entityClass);
        $typeName = $ctSchema['slug'];
        $locales = $contentType->locales ?? ['fr'];

        $translationClass = $this->getTranslationClass($reflClass);
        $fieldMappings = $this->buildFieldMappings($reflClass, $entityClass);
        $dependencies = $this->detectDependencies($reflClass, $entityClass);

        return new ExportSchema(
            typeName: $typeName,
            entityClass: $entityClass,
            translationClass: $translationClass,
            locales: $locales,
            singleton: $contentType->singleton,
            fieldMappings: $fieldMappings,
            dependencies: $dependencies,
        );
    }

    /**
     * @return FieldMapping[]
     */
    private function buildFieldMappings(\ReflectionClass $reflClass, string $entityClass): array
    {
        $mappings = [];

        foreach ($reflClass->getProperties() as $property) {
            $fieldAttr = $this->getFieldAttribute($property);

            if ($fieldAttr === null) {
                continue;
            }

            $fieldType = $fieldAttr->getFieldType();

            // Skip slug (identity) and readonly fields
            if ($fieldType === 'slug') {
                continue;
            }

            if ($fieldAttr instanceof FieldAttribute && $fieldAttr->readonly) {
                continue;
            }

            $mapping = $this->buildFieldMapping($property, $fieldAttr, $entityClass, $reflClass);

            if ($mapping !== null) {
                $mappings[] = $mapping;
            }
        }

        return $mappings;
    }

    private function buildFieldMapping(
        \ReflectionProperty $property,
        FieldAttributeInterface $fieldAttr,
        string $entityClass,
        \ReflectionClass $reflClass,
    ): ?FieldMapping {
        $propertyName = $property->getName();
        $fieldType = $fieldAttr->getFieldType();
        $exportName = $this->resolveExportName($property, $propertyName);
        $getter = 'get' . ucfirst($propertyName);

        if (!$reflClass->hasMethod($getter)) {
            $getter = 'is' . ucfirst($propertyName);
            if (!$reflClass->hasMethod($getter)) {
                return null;
            }
        }

        $handler = $this->createHandler($property, $fieldAttr, $entityClass);

        if ($handler === null) {
            return null;
        }

        $isTranslatable = $this->isTranslatable($property);

        // Determine write accessors
        $isCollection = $this->isCollectionField($fieldType, $fieldAttr);
        $setter = null;
        $adder = null;
        $remover = null;

        if ($isCollection) {
            [$adder, $remover] = $this->findAdderRemover($reflClass, $propertyName);

            // Fall back to setter if no adder/remover found
            if ($adder === null) {
                $setterName = 'set' . ucfirst($propertyName);
                if ($reflClass->hasMethod($setterName)) {
                    $setter = $setterName;
                }
            }
        } else {
            $setterName = 'set' . ucfirst($propertyName);
            if ($reflClass->hasMethod($setterName)) {
                $setter = $setterName;
            }
        }

        return new FieldMapping(
            propertyName: $propertyName,
            exportName: $exportName,
            getter: $getter,
            handler: $handler,
            setter: $setter,
            adder: $adder,
            remover: $remover,
            translatable: $isTranslatable,
        );
    }

    private function createHandler(
        \ReflectionProperty $property,
        FieldAttributeInterface $fieldAttr,
        string $entityClass,
    ): ?FieldHandler {
        $fieldType = $fieldAttr->getFieldType();

        return match ($fieldType) {
            'image', 'file' => new MediaFieldHandler(),
            'imagelist', 'filelist' => new CollectionFieldHandler(new MediaFieldHandler()),
            'relation' => $this->createRelationHandler($property, $fieldAttr, $entityClass),
            'entity_taxonomy' => $this->createEntityTaxonomyHandler($fieldAttr),
            'date' => new ScalarFieldHandler($this->getPhpType($property)),
            default => new ScalarFieldHandler(),
        };
    }

    private function createRelationHandler(
        \ReflectionProperty $property,
        FieldAttributeInterface $fieldAttr,
        string $entityClass,
    ): FieldHandler {
        $classMetadata = $this->entityManager->getClassMetadata($entityClass);
        $propertyName = $property->getName();

        if (!$classMetadata->hasAssociation($propertyName)) {
            return new ScalarFieldHandler();
        }

        $targetClass = $classMetadata->getAssociationTargetClass($propertyName);
        $isMultiple = $fieldAttr instanceof RelationField && $fieldAttr->multiple;

        // Determine identifier strategy for the target entity
        $identifierGetter = 'getSlug';
        $identifierField = 'slug';

        $targetReflClass = new \ReflectionClass($targetClass);

        if ($targetReflClass->implementsInterface(UserInterface::class)) {
            $identifierGetter = 'getUsername';
            $identifierField = 'username';
        }

        $itemHandler = new RelationFieldHandler(
            resolver: $this->referenceResolver,
            targetEntityClass: $targetClass,
            identifierGetter: $identifierGetter,
            identifierField: $identifierField,
        );

        return $isMultiple ? new CollectionFieldHandler($itemHandler) : $itemHandler;
    }

    private function createEntityTaxonomyHandler(FieldAttributeInterface $fieldAttr): FieldHandler
    {
        $taxonomyType = null;

        if ($fieldAttr instanceof EntityTaxonomyField && $fieldAttr->filter !== null) {
            // Parse "type=genres" format
            if (str_starts_with($fieldAttr->filter, 'type=')) {
                $taxonomyType = substr($fieldAttr->filter, 5);
            }
        }

        $itemHandler = new TaxonomyFieldHandler(
            resolver: $this->referenceResolver,
            taxonomyType: $taxonomyType,
        );

        return new CollectionFieldHandler($itemHandler);
    }

    /**
     * @return string[]
     */
    private function detectDependencies(\ReflectionClass $reflClass, string $entityClass): array
    {
        $dependencies = [];
        $classMetadata = $this->entityManager->getClassMetadata($entityClass);

        foreach ($reflClass->getProperties() as $property) {
            $fieldAttr = $this->getFieldAttribute($property);

            if ($fieldAttr === null || $fieldAttr->getFieldType() !== 'relation') {
                continue;
            }

            if ($fieldAttr instanceof FieldAttribute && $fieldAttr->readonly) {
                continue;
            }

            $propertyName = $property->getName();

            if (!$classMetadata->hasAssociation($propertyName)) {
                continue;
            }

            $targetClass = $classMetadata->getAssociationTargetClass($propertyName);

            if (isset($this->classToTypeMap[$targetClass])) {
                $targetType = $this->classToTypeMap[$targetClass];
                $ownType = $this->classToTypeMap[$entityClass] ?? null;

                // Don't self-reference as dependency
                if ($targetType !== $ownType && !\in_array($targetType, $dependencies, true)) {
                    $dependencies[] = $targetType;
                }
            }
        }

        return $dependencies;
    }

    private function getFieldAttribute(\ReflectionProperty $property): ?FieldAttributeInterface
    {
        foreach ($property->getAttributes() as $attr) {
            $instance = $attr->newInstance();

            if ($instance instanceof FieldAttributeInterface) {
                return $instance;
            }
        }

        return null;
    }

    private function resolveExportName(\ReflectionProperty $property, string $propertyName): string
    {
        $serializedNameAttrs = $property->getAttributes(SerializedName::class);

        if ($serializedNameAttrs !== []) {
            return $serializedNameAttrs[0]->newInstance()->getSerializedName();
        }

        return $this->toSnakeCase($propertyName);
    }

    private function isTranslatable(\ReflectionProperty $property): bool
    {
        return $property->getAttributes(Gedmo\Translatable::class) !== [];
    }

    private function isCollectionField(string $fieldType, FieldAttributeInterface $fieldAttr): bool
    {
        if (\in_array($fieldType, ['imagelist', 'filelist', 'entity_taxonomy'], true)) {
            return true;
        }

        if ($fieldType === 'relation' && $fieldAttr instanceof RelationField) {
            return $fieldAttr->multiple;
        }

        return false;
    }

    /**
     * @return array{string, string}|array{null, null}
     */
    private function findAdderRemover(\ReflectionClass $reflClass, string $propertyName): array
    {
        $singular = $this->singularize($propertyName);
        $adder = 'add' . ucfirst($singular);
        $remover = 'remove' . ucfirst($singular);

        if ($reflClass->hasMethod($adder) && $reflClass->hasMethod($remover)) {
            return [$adder, $remover];
        }

        return [null, null];
    }

    private function singularize(string $word): string
    {
        if (str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }

        if (str_ends_with($word, 'ses') || str_ends_with($word, 'xes') || str_ends_with($word, 'zes')) {
            return substr($word, 0, -2);
        }

        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    private function toSnakeCase(string $input): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', lcfirst($input)));
    }

    private function getPhpType(\ReflectionProperty $property): ?string
    {
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        return null;
    }

    /**
     * @return class-string|null
     */
    private function getTranslationClass(\ReflectionClass $reflClass): ?string
    {
        $attrs = $reflClass->getAttributes(Gedmo\TranslationEntity::class);

        if ($attrs !== []) {
            /** @var Gedmo\TranslationEntity $translationEntity */
            $translationEntity = $attrs[0]->newInstance();

            /** @var class-string */
            return $translationEntity->class;
        }

        return null;
    }
}
