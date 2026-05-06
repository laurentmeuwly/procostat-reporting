<?php

namespace Procorad\ProcostatReporting\Infrastructure;

use Procorad\ProcostatReporting\Contract\ChartRendererInterface;
use Procorad\ProcostatReporting\ValueObject\PlotSpec;
use Procorad\ProcostatReporting\ValueObject\RenderedChart;

final class JsonDebugRenderer implements ChartRendererInterface
{
    public function render(PlotSpec $plot): RenderedChart
    {
        return new RenderedChart(
            mimeType: 'application/json',
            binaryContent: json_encode($plot, JSON_PRETTY_PRINT)
        );
    }
}
