<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\FieldHandler;

final class ScalarFieldHandler implements FieldHandler
{
    public function __construct(
        private readonly ?string $phpType = null,
    ) {
    }

    public function extract(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return $value;
    }

    public function hydrate(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->phpType !== null && \is_string($value)) {
            if (is_a($this->phpType, \DateTimeImmutable::class, true)) {
                return new \DateTimeImmutable($value);
            }

            if (is_a($this->phpType, \DateTime::class, true)) {
                return new \DateTime($value);
            }
        }

        return $value;
    }
}
