<?php

namespace Procorad\ProcostatReporting\Data;

/**
 * Top-level DTO representing one intercomparison exercise.
 *
 * @param SampleAnalysisData[]    $analyses            One per sample × isotope, ordered as desired.
 * @param UnexpectedIsotopeData[] $unexpectedIsotopes  Isotopes found but not expected; one entry per
 *                                                     isotope, with one finding per reporting lab.
 *                                                     Only labs with an actual value (not <LD) are
 *                                                     included. Empty array if none.
 * @param array<string,mixed>     $metadata            Arbitrary key/value (locale, generatedAt, …)
 */
final class IntercomparisonReportData
{
    public function __construct(
        public readonly string $icCode,
        public readonly string $icTitle,
        public readonly int    $year,
        public readonly array  $analyses,              // SampleAnalysisData[]
        public readonly array  $unexpectedIsotopes = [], // UnexpectedIsotopeData[]
        public readonly array  $metadata           = [],
    ) {}

    /** Serialise for Node.js JSON payload. */
    public function toArray(): array
    {
        return [
            'icCode'             => $this->icCode,
            'icTitle'            => $this->icTitle,
            'year'               => $this->year,
            'analyses'           => array_map(fn(SampleAnalysisData $a) => $a->toArray(), $this->analyses),
            'unexpectedIsotopes' => array_values(array_map(
                fn(UnexpectedIsotopeData $u) => $u->toArray(),
                array_filter(
                    $this->unexpectedIsotopes,
                    fn(UnexpectedIsotopeData $u) => $u->hasDisplayableValues(),
                ),
            )),
            'metadata'           => $this->metadata,
        ];
    }
}
