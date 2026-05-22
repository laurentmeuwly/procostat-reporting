<?php

namespace Procorad\ProcostatReporting\Data;

/**
 * Immutable DTO for one lab's result within a ProcostatAnalysis.
 *
 * Raw measurement values (activity, expandedUncertainty, detectionLimit) come
 * exclusively from the ProcostatAnalysisMeasurement snapshot — never from
 * submission_results, which may have been modified after calculation.
 *
 * The three measurement flags (isBelowLod, isIncluded, isTruncated) are carried
 * forward for future xlsx styling (e.g. grey row for excluded labs, italic for
 * winsorised values) but are not yet used by the generator.
 */
final class LabResultData
{
    public function __construct(
        public readonly string $laboratoryCode,
        public readonly int    $labNumber,

        // ── Immutable measurement snapshot (ProcostatAnalysisMeasurement) ──
        public readonly ?float $activity,
        public readonly ?float $expandedUncertainty,
        public readonly ?float $detectionLimit,

        // Measurement flags
        public readonly bool   $isBelowLod  = false,
        public readonly bool   $isIncluded  = true,
        public readonly bool   $isTruncated = false,

        // ── Computed scores (ProcostatLabResult) ────────────────────────────
        public readonly ?float  $zScore,
        public readonly ?float  $zPrimeScore,
        public readonly ?float  $zetaScore,
        public readonly ?float  $biasPercent,
        public readonly ?float  $enScore      = null,  // not used this year

        // ── Status ──────────────────────────────────────────────────────────
        public readonly ?string $fitnessStatus     = null,
        public readonly ?string $evaluationValidity = null,
    ) {}

    /** Serialise for Node.js JSON payload. */
    public function toArray(): array
    {
        return [
            'laboratoryCode'      => $this->laboratoryCode,
            'labNumber'           => $this->labNumber,
            'activity'            => $this->activity,
            'expandedUncertainty' => $this->expandedUncertainty,
            'detectionLimit'      => $this->detectionLimit,
            'isBelowLod'          => $this->isBelowLod,
            'isIncluded'          => $this->isIncluded,
            'isTruncated'         => $this->isTruncated,
            'zScore'              => $this->zScore,
            'zPrimeScore'         => $this->zPrimeScore,
            'zetaScore'           => $this->zetaScore,
            'biasPercent'         => $this->biasPercent,
            'enScore'             => $this->enScore,
            'fitnessStatus'       => $this->fitnessStatus,
            'evaluationValidity'  => $this->evaluationValidity,
        ];
    }
}
