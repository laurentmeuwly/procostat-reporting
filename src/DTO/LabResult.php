<?php

declare(strict_types=1);

namespace Procorad\ProcostatReporting\DTO;

final readonly class LabResult
{
    public function __construct(
        public int $labNumber,
        public float $activity,
        public float $expandedUncertainty,
        public float $detectionLimit,
        public float $bias,
        public float $enScore,
        public float $zscore,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'labNumber'           => $this->labNumber,
            'activity'            => $this->activity,
            'expandedUncertainty' => $this->expandedUncertainty,
            'detectionLimit'      => $this->detectionLimit,
            'bias'                => $this->bias,
            'enScore'             => $this->enScore,
            'zscore'              => $this->zscore,
        ];
    }
}
