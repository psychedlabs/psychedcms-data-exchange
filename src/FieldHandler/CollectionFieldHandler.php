<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\FieldHandler;

use Doctrine\Common\Collections\Collection;

final class CollectionFieldHandler implements FieldHandler
{
    public function __construct(
        private readonly FieldHandler $itemHandler,
    ) {
    }

    public function extract(mixed $value): array
    {
        $items = $value instanceof Collection ? $value->toArray() : ($value ?? []);

        return array_values(array_map(
            fn (mixed $item): mixed => $this->itemHandler->extract($item),
            $items,
        ));
    }

    public function hydrate(mixed $value): array
    {
        return array_map(
            fn (mixed $item): mixed => $this->itemHandler->hydrate($item),
            $value ?? [],
        );
    }
}
