<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\FieldHandler;

use PsychedCms\DataExchange\Import\ReferenceResolver;

final class RelationFieldHandler implements FieldHandler
{
    public function __construct(
        private readonly ReferenceResolver $resolver,
        private readonly string $targetEntityClass,
        private readonly string $identifierGetter = 'getSlug',
        private readonly string $identifierField = 'slug',
    ) {
    }

    public function extract(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return $value->{$this->identifierGetter}();
    }

    public function hydrate(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->resolver->resolve($this->targetEntityClass, (string) $value, $this->identifierField);
    }
}
