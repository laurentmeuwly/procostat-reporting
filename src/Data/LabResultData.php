<?php

namespace Procorad\ProcostatReporting\Data;

/**
 * Immutable DTO carrying one lab's result for a given analysis.
 * Built by the app-side factory — no App\Models dependency in the package.
 *
 * v2 additions: activity, expandedUncertainty, detectionLimit, enScore
 * These were present on ProcostatLabResult but missing from this DTO,
 * causing IntercomparisonReportLabRow to receive nulls.
 */
final class LabResultData
{
    public function __construct(
        public readonly string  $laboratoryCode,
        public readonly int     $labNumber,

        // Raw measurement (needed for XLSX data sheet + DOCX/PPTX tables)
        public readonly ?float  $activity,
        public readonly ?float  $expandedUncertainty,
        public readonly ?float  $detectionLimit,

        // Scores
        public readonly ?float  $zScore,
        public readonly ?float  $zPrimeScore,
        public readonly ?float  $zetaScore,
        public readonly ?float  $enScore,
        public readonly ?float  $biasPercent,

        // Status
        public readonly ?string $fitnessStatus,      // 'ok' | 'questionable' | 'discrepant'
        public readonly ?string $evaluationValidity, // 'valid' | 'below_ld' | 'no_answer' | …
    ) {}

    /** Serialise to plain array for Node.js JSON payload. */
    public function toArray(): array
    {
        return [
            'laboratoryCode'      => $this->laboratoryCode,
            'labNumber'           => $this->labNumber,
            'activity'            => $this->activity,
            'expandedUncertainty' => $this->expandedUncertainty,
            'detectionLimit'      => $this->detectionLimit,
            'zScore'              => $this->zScore,
            'zPrimeScore'         => $this->zPrimeScore,
            'zetaScore'           => $this->zetaScore,
            'enScore'             => $this->enScore,
            'biasPercent'         => $this->biasPercent,
            'fitnessStatus'       => $this->fitnessStatus,
            'evaluationValidity'  => $this->evaluationValidity,
        ];
    }
}
