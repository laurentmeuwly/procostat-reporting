<?php

namespace Procorad\ProcostatReporting\Infrastructure;

use Procorad\ProcostatReporting\ValueObject\PlotSpec;

final class ChartJsConfigBuilder
{
    public function fromPlotSpec(PlotSpec $plot): array
    {
        $series = $plot->series[0];

        return [
            'type' => $plot->type->value,
            'labels' => $series->labels,
            'values' => $series->values,
            'datasetLabel' => $series->label,
        ];
    }
}
