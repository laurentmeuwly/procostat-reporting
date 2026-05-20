<?php

namespace Procorad\ProcostatReporting\Model;

final class IntercomparisonReportSamplePage
{
    /**
     * @param IntercomparisonReportLabRow[] $rows  Ordered by lab anonymisation number.
     */
    public function __construct(
        public readonly string  $sampleCode,
        public readonly string  $isotope,
        public readonly string  $matrix,
        public readonly string  $unit,
        public readonly ?float  $assignedValue,
        public readonly ?float  $assignedUncertainty,
        public readonly ?float  $robustMean,
        public readonly array   $rows,
    ) {}
}
