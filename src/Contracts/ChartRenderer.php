<?php

namespace Procorad\ProcostatReporting\Contract;

use Procorad\ProcostatReporting\ValueObject\ReportingContext;
use Procorad\ProcostatReporting\ValueObject\ScatterSeries;
use Procorad\ProcostatReporting\ValueObject\Series;
use Procorad\ProcostatReporting\ValueObject\ThresholdBands;

interface ChartRenderer
{
    public function render(
        string $chartId,
        Series|ScatterSeries $data,
        ThresholdBands $thresholds,
        ReportingContext $context,
    ): Asset;
}
