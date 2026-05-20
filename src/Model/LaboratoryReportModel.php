<?php

namespace Procorad\ProcostatReporting\Model;

final class LaboratoryReportModel
{
    /**
     * @param IntercomparisonPageModel[] $pages
     */
    public function __construct(
        public readonly int    $labNumber,
        public readonly int    $year,
        //public readonly string $eventLocation,
        public readonly array  $pages,           // une entrée par IC
    ) {}
}
