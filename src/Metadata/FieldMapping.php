<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Metadata;

use PsychedCms\DataExchange\FieldHandler\FieldHandler;

final readonly class FieldMapping
{
    public function __construct(
        public string $propertyName,
        public string $exportName,
        public string $getter,
        public FieldHandler $handler,
        public ?string $setter = null,
        public ?string $adder = null,
        public ?string $remover = null,
        public bool $translatable = false,
    ) {
    }

    public function readFrom(object $entity): mixed
    {
        return $entity->{$this->getter}();
    }

    public function writeTo(object $entity, mixed $value): void
    {
        if ($this->setter !== null) {
            $entity->{$this->setter}($value);

            return;
        }

        if ($this->adder !== null && $this->remover !== null) {
            foreach ($entity->{$this->getter}() as $existing) {
                $entity->{$this->remover}($existing);
            }

            foreach ($value as $item) {
                if ($item !== null) {
                    $entity->{$this->adder}($item);
                }
            }
        }
    }
}
