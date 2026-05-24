<?php

namespace Procorad\ProcostatReporting\Data;

/**
 * Immutable DTO for one lab's result within a ProcostatAnalysis.
 *
 * Raw measurement values come exclusively from ProcostatAnalysisMeasurement
 * (immutable snapshot) — never from submission_results.
 *
 * All labs are included regardless of exclusion status, so the Excel table
 * shows the full population. The exclusion flags drive the "Exclu des stats"
 * column and row styling.
 */
final class LabResultData
{
    public function __construct(
        public readonly string  $laboratoryCode,
        public readonly int     $labNumber,

        // ── Immutable measurement snapshot (ProcostatAnalysisMeasurement) ──────
        public readonly ?float  $activity,
        public readonly ?float  $expandedUncertainty,
        public readonly ?float  $detectionLimit,

        // Measurement flags
        public readonly bool    $isBelowLod     = false,
        public readonly bool    $isIncluded     = true,
        public readonly bool    $isTruncated    = false,

        /**
         * Exclusion/truncation reason from ProcostatAnalysisMeasurement::exclusion_reason.
         * Values: null | 'below_lod' | 'outlier_grubbs' | 'outlier_dixon' | 'manual'
         * Used to populate the "Exclu des stats" column in the Excel table.
         */
        public readonly ?string $exclusionReason = null,

        /**
         * Winsorised value from ProcostatAnalysisMeasurement::truncated_value.
         * Non-null only when isTruncated = true.
         * Displayed in italics in the Excel table to signal the value was capped.
         */
        public readonly ?float  $truncatedValue  = null,

        // ── Computed scores (ProcostatLabResult) ─────────────────────────────────
        public readonly ?float  $zScore       = null,
        public readonly ?float  $zPrimeScore  = null,
        public readonly ?float  $zetaScore    = null,
        public readonly ?float  $biasPercent  = null,
        public readonly ?float  $enScore      = null,

        // ── Status ────────────────────────────────────────────────────────────────
        public readonly ?string $fitnessStatus      = null,
        public readonly ?string $evaluationValidity = null,
    ) {}

    /**
     * Human-readable exclusion label for the "Exclu des stats" column.
     * Returns null when the lab is included in statistics.
     */
    public function exclusionLabel(): ?string
    {
        if ($this->isIncluded && ! $this->isTruncated) {
            return null;
        }

        if ($this->isTruncated) {
            return 'Tronqué (z > 5)';
        }

        return match ($this->exclusionReason) {
            'outlier_grubbs' => 'Aberrant (Grubbs)',
            'outlier_dixon'  => 'Aberrant (Dixon)',
            'below_lod'      => 'Sous LD',
            'manual'         => 'Exclu manuellement',
            default          => 'Exclu',
        };
    }

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
            'exclusionReason'     => $this->exclusionReason,
            'truncatedValue'      => $this->truncatedValue,
            'exclusionLabel'      => $this->exclusionLabel(),
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
