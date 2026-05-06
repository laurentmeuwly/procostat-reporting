<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class TableColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type, // string | float | int | status
        public readonly ?string $unit = null,
    ) {}
}
