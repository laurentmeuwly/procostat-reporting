<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\DTO;

final readonly class ZscoreLimits
{
    public function __construct(
        public float $warningLow  = -2.0,
        public float $warningHigh = 2.0,
        public float $actionLow   = -3.0,
        public float $actionHigh  = 3.0,
    ) {}

    /** @return array<string, float> */
    public function toArray(): array
    {
        return [
            'warningLow'  => $this->warningLow,
            'warningHigh' => $this->warningHigh,
            'actionLow'   => $this->actionLow,
            'actionHigh'  => $this->actionHigh,
        ];
    }
}
