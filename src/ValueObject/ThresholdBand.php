<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class ThresholdBand
{
    public function __construct(
        public readonly float $min,
        public readonly float $max,
        public readonly string $label,
    ) {
        if ($min >= $max) {
            throw new \InvalidArgumentException('Threshold min must be < max.');
        }
    }
}
