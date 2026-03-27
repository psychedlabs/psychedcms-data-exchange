<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\FieldHandler;

final class MediaFieldHandler implements FieldHandler
{
    public function extract(mixed $value): mixed
    {
        if ($value === null || !\is_array($value)) {
            return $value;
        }

        $exported = [];

        if (isset($value['storagePath'])) {
            $exported['storagePath'] = $value['storagePath'];
        }

        if (isset($value['alt'])) {
            $exported['alt'] = $value['alt'];
        }

        if (isset($value['mimeType'])) {
            $exported['mimeType'] = $value['mimeType'];
        }

        return $exported !== [] ? $exported : null;
    }

    public function hydrate(mixed $value): mixed
    {
        return $value;
    }
}
