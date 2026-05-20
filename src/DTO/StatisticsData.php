<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\DTO;

final readonly class StatisticsData
{
    public function __construct(
        public int $numberOfResults,
        public float $assignedValue,
        public float $assignedUncertainty,
        public float $robustMean,
        public float $robustDeviation,
        public float $geometricalMean,
        public float $minValue,
        public float $maxValue,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'numberOfResults'     => $this->numberOfResults,
            'assignedValue'       => $this->assignedValue,
            'assignedUncertainty' => $this->assignedUncertainty,
            'robustMean'          => $this->robustMean,
            'robustDeviation'     => $this->robustDeviation,
            'geometricalMean'     => $this->geometricalMean,
            'minValue'            => $this->minValue,
            'maxValue'            => $this->maxValue,
        ];
    }
}
