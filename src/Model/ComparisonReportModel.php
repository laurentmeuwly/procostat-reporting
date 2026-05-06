<?php

namespace Procorad\ProcostatReporting\Model;

use Procorad\ProcostatReporting\ValueObject\Table;

final class ComparisonReportModel
{
    /**
     * @param Table $summaryTable
     * @param array<string, Series|ScatterSeries> $plots
     */
    public function __construct(
        public readonly Table $summaryTable,
        public readonly array $plots,
    ) {}
}
