<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class Series
{
    /**
     * @param string[] $labels
     * @param float[]  $values
     */
    public function __construct(
        public readonly array $labels,
        public readonly array $values,
        public readonly string $label,
    ) {
        if (count($labels) !== count($values)) {
            throw new \InvalidArgumentException('Labels and values must have same size.');
        }
    }
}
