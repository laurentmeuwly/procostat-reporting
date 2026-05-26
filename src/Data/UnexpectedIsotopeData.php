<?php

namespace Procorad\ProcostatReporting\Data;

/**
 * Measurements of an isotope that was not expected in the intercomparison
 * exercise but was reported by one or more laboratories.
 *
 * Only labs that provided an actual value are included here (isBelowLod = false).
 * Labs that reported "<LD" are excluded from the display per business rules.
 *
 * One UnexpectedIsotopeData per isotope found; multiple labs may have measured
 * the same unexpected isotope.
 */
final class UnexpectedIsotopeData
{
    /**
     * @param string  $isotope    e.g. "241Am", "137Cs"
     * @param string  $unit       e.g. "Bq/l", "Bq/kg"
     * @param array   $findings   UnexpectedLabFinding[]
     */
    public function __construct(
        public readonly string $isotope,
        public readonly string $unit,
        public readonly array  $findings,  // UnexpectedLabFinding[]
    ) {}

    /** Returns true if at least one lab reported a non-<LD value — use to decide whether to display. */
    public function hasDisplayableValues(): bool
    {
        return !empty(array_filter($this->findings, fn($f) => !$f['isBelowLod']));
    }

    public function toArray(): array
    {
        return [
            'isotope'  => $this->isotope,
            'unit'     => $this->unit,
            'findings' => $this->findings,
        ];
    }

    /**
     * Convenience factory for a single lab finding.
     * Returns the finding array to be collected into $findings.
     *
     * @param int    $labNumber
     * @param ?float $activity           Measured activity (null if <LD)
     * @param ?float $expandedUncertainty
     * @param bool   $isBelowLod
     */
    public static function finding(
        int    $labNumber,
        ?float $activity,
        ?float $expandedUncertainty = null,
        ?float $detectionLimit      = null,
        bool   $isBelowLod          = false,
    ): array {
        return [
            'labNumber'           => $labNumber,
            'activity'            => $activity,
            'expandedUncertainty' => $expandedUncertainty,
            'detectionLimit'      => $detectionLimit,
            'isBelowLod'          => $isBelowLod,
        ];
    }
}
