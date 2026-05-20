<?php

namespace Procorad\ProcostatReporting\Model;

use Procorad\ProcostatReporting\ValueObject\RenderedChart;

final class IntercomparisonPageModel
{
    public function __construct(
        public readonly string        $icTitle,
        //public readonly string        $matrix,
        //public readonly string        $unit,
        //public readonly array         $rows,           // tableau de résultats
        //public readonly RenderedChart $biasChart,      // PNG généré
    ) {}
}
