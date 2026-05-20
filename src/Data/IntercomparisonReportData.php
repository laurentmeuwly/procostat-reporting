<?php

namespace Procorad\ProcostatReporting\Data;

/**
 * Top-level DTO representing one intercomparison exercise.
 *
 * @param SampleAnalysisData[] $analyses  One per sample × isotope, ordered as desired.
 * @param array<string,mixed>  $metadata  Arbitrary key/value (locale, generatedAt, …)
 */
final class IntercomparisonReportData
{
    public function __construct(
        public readonly string $icCode,
        public readonly string $icTitle,
        public readonly int    $year,
        public readonly array  $analyses,   // SampleAnalysisData[]
        public readonly array  $metadata = [],
    ) {}

    /** Serialise for Node.js JSON payload. */
    public function toArray(): array
    {
        return [
            'icCode'    => $this->icCode,
            'icTitle'   => $this->icTitle,
            'year'      => $this->year,
            'analyses'  => array_map(fn(SampleAnalysisData $a) => $a->toArray(), $this->analyses),
            'metadata'  => $this->metadata,
        ];
    }
}
