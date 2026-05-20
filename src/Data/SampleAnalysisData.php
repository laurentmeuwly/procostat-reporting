<?php

namespace Procorad\ProcostatReporting\Data;

/**
 * Immutable DTO representing one ProcostatAnalysis (sample × isotope)
 * together with all its lab results.
 *
 * @param LabResultData[] $labResults
 */
final class SampleAnalysisData
{
    public function __construct(
        public readonly string  $sampleCode,
        public readonly string  $isotope,
        public readonly string  $matrix,
        public readonly string  $unit,
        public readonly ?float  $assignedValue,
        public readonly ?float  $assignedUncertainty,
        public readonly ?float  $robustMean,
        public readonly ?float  $robustStdDev,
        public readonly ?string $primaryIndicator,   // 'z' | 'z_prime'
        public readonly array   $labResults,          // LabResultData[]
    ) {}

    /** Serialise for Node.js JSON payload. */
    public function toArray(): array
    {
        return [
            'sampleCode'          => $this->sampleCode,
            'isotope'             => $this->isotope,
            'matrix'              => $this->matrix,
            'unit'                => $this->unit,
            'assignedValue'       => $this->assignedValue,
            'assignedUncertainty' => $this->assignedUncertainty,
            'robustMean'          => $this->robustMean,
            'robustStdDev'        => $this->robustStdDev,
            'primaryIndicator'    => $this->primaryIndicator,
            'labResults'          => array_map(fn(LabResultData $r) => $r->toArray(), $this->labResults),
        ];
    }
}
