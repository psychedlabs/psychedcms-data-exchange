<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Worker;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\DataExchange\Import\ReferenceResolver;
use PsychedCms\Taxonomy\Entity\Taxonomy;

final class TaxonomyWorker
{
    /**
     * @return array<string, mixed>
     */
    public function export(Taxonomy $taxonomy): array
    {
        $data = [
            'type' => $taxonomy->getType(),
            'slug' => $taxonomy->getSlug(),
            'name' => $taxonomy->getName(),
            'position' => $taxonomy->getTaxonomyTermPosition(),
        ];

        if ($taxonomy->getParent() !== null) {
            $data['parent'] = $taxonomy->getParent()->getSlug();
        }

        return $data;
    }

    /**
     * Import taxonomies in topological order (parents before children).
     *
     * @param list<array<string, mixed>> $records
     *
     * @return int Number of imported records
     */
    public function importAll(
        array $records,
        EntityManagerInterface $em,
        ReferenceResolver $resolver,
    ): int {
        // Sort: entries without parent first, then by parent reference
        $withoutParent = [];
        $withParent = [];

        foreach ($records as $record) {
            if (isset($record['parent'])) {
                $withParent[] = $record;
            } else {
                $withoutParent[] = $record;
            }
        }

        $count = 0;

        // Import root taxonomies first
        foreach ($withoutParent as $record) {
            $this->importOne($record, $em, $resolver);
            ++$count;
        }

        $em->flush();

        // Then children (may need multiple passes for deep hierarchies)
        $remaining = $withParent;
        $maxPasses = 10;

        while ($remaining !== [] && $maxPasses-- > 0) {
            $nextRound = [];

            foreach ($remaining as $record) {
                $parent = $resolver->resolveTaxonomy($record['type'], $record['parent']);

                if ($parent !== null) {
                    $this->importOne($record, $em, $resolver, $parent);
                    ++$count;
                } else {
                    $nextRound[] = $record;
                }
            }

            $em->flush();
            $remaining = $nextRound;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function importOne(
        array $data,
        EntityManagerInterface $em,
        ReferenceResolver $resolver,
        ?Taxonomy $parent = null,
    ): Taxonomy {
        $type = $data['type'];
        $slug = $data['slug'];

        $taxonomy = $em->getRepository(Taxonomy::class)->findOneBy([
            'type' => $type,
            'slug' => $slug,
        ]);

        if ($taxonomy === null) {
            $taxonomy = new Taxonomy();
            $taxonomy->setType($type);
            $taxonomy->setSlug($slug);
        }

        $taxonomy->setName($data['name']);
        $taxonomy->setTaxonomyTermPosition($data['position'] ?? 0);

        if ($parent !== null) {
            $taxonomy->setParent($parent);
        }

        $em->persist($taxonomy);

        // Register for reference resolution
        $resolver->register(Taxonomy::class, $slug, $taxonomy, 'slug');

        return $taxonomy;
    }
}
