<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class ScatterSeries
{
    /**
     * @param array<int,array{x:float,y:float,label?:string}> $points
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $points,
    ) {
        $this->assertValid();
    }

    private function assertValid(): void
    {
        foreach ($this->points as $index => $point) {
            if (!isset($point['x'], $point['y'])) {
                throw new \InvalidArgumentException(
                    "Scatter point {$index} must contain x and y"
                );
            }
        }
    }
}
