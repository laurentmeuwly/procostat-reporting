<?php

namespace Procorad\ProcostatReporting\Contract;

use Procorad\ProcostatReporting\ValueObject\RenderedChart;

use Procorad\ProcostatReporting\ValueObject\PlotSpec;

interface ChartRendererInterface
{
    public function render(PlotSpec $plot): RenderedChart;
}
