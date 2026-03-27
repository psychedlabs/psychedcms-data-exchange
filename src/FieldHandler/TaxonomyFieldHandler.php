<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\FieldHandler;

use PsychedCms\DataExchange\Import\ReferenceResolver;

final class TaxonomyFieldHandler implements FieldHandler
{
    public function __construct(
        private readonly ReferenceResolver $resolver,
        private readonly ?string $taxonomyType = null,
    ) {
    }

    public function extract(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return $value->getSlug();
    }

    public function hydrate(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->resolver->resolveTaxonomy((string) $this->taxonomyType, (string) $value);
    }
}
