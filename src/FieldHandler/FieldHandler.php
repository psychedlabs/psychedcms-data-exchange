<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\FieldHandler;

interface FieldHandler
{
    public function extract(mixed $value): mixed;

    public function hydrate(mixed $value): mixed;
}
