<?php

namespace Procorad\ProcostatReporting\Model;

final class IntercomparisonReportModel
{
    /**
     * @param IntercomparisonReportSamplePage[] $pages  One per sample × isotope.
     */
    public function __construct(
        public readonly string $icCode,
        public readonly string $icTitle,
        public readonly int    $year,
        public readonly array  $pages,
    ) {}
}
