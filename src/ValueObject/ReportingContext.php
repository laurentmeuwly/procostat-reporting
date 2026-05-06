<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class ReportingContext
{
    public function __construct(
        public readonly string $campaignId,
        public readonly string $comparisonCode,
        public readonly string $sampleLabel,
        public readonly string $isotope,
        public readonly string $unit,
        public readonly ?\DateTimeImmutable $referenceDate,
        public readonly string $locale = 'fr',
    ) {
        if ($this->campaignId === '') {
            throw new \InvalidArgumentException('Campaign ID cannot be empty.');
        }
    }
}
