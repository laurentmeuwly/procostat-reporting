<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class ThresholdBands
{
    /**
     * @param float[] $levels
     */
    public function __construct(
        public readonly array $levels,
    ) {
        $this->assertValid();
    }

    private function assertValid(): void
    {
        foreach ($this->levels as $level) {
            if ($level <= 0) {
                throw new \InvalidArgumentException(
                    'Threshold levels must be positive numbers'
                );
            }
        }
    }

    public static function zScore(): self
    {
        return new self([2.0, 3.0]);
    }

    public static function none(): self
    {
        return new self([]);
    }
}
