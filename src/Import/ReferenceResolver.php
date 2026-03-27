<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Import;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\Taxonomy\Entity\Taxonomy;

final class ReferenceResolver
{
    /** @var array<string, object|null> */
    private array $cache = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(string $entityClass, string $identifier, string $field = 'slug'): ?object
    {
        $key = $entityClass . ':' . $field . ':' . $identifier;

        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $entity = $this->entityManager->getRepository($entityClass)->findOneBy([$field => $identifier]);

        if ($entity === null) {
            $shortClass = (new \ReflectionClass($entityClass))->getShortName();
            $this->warnings[] = \sprintf('Missing reference: %s[%s=%s]', $shortClass, $field, $identifier);
        }

        $this->cache[$key] = $entity;

        return $entity;
    }

    public function resolveTaxonomy(string $type, string $slug): ?Taxonomy
    {
        $key = 'taxonomy:' . $type . ':' . $slug;

        if (\array_key_exists($key, $this->cache)) {
            /** @var Taxonomy|null */
            return $this->cache[$key];
        }

        $entity = $this->entityManager->getRepository(Taxonomy::class)->findOneBy([
            'type' => $type,
            'slug' => $slug,
        ]);

        if ($entity === null) {
            $this->warnings[] = \sprintf('Missing taxonomy: %s/%s', $type, $slug);
        }

        $this->cache[$key] = $entity;

        /** @var Taxonomy|null */
        return $entity;
    }

    /**
     * Register an entity in the cache so future resolve() calls find it.
     */
    public function register(string $entityClass, string $identifier, object $entity, string $field = 'slug'): void
    {
        $key = $entityClass . ':' . $field . ':' . $identifier;
        $this->cache[$key] = $entity;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    public function clearWarnings(): void
    {
        $this->warnings = [];
    }
}
