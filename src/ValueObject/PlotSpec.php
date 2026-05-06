<?php

namespace Procorad\ProcostatReporting\ValueObject;

final class PlotSpec
{
    /**
     * @param Series[] $series
     * @param ThresholdBand[] $thresholds
     */
    public function __construct(
        public readonly PlotType $type,
        public readonly string $title,
        public readonly string $yLabel,
        public readonly array $series,
        public readonly array $thresholds = [],
    ) {
        if (empty($series)) {
            throw new \InvalidArgumentException('PlotSpec requires at least one series.');
        }
    }
}

/*
PlotSpec::barChart(
    title: "z'-score - 26XGA - 40K",
    xLabels: ["Lab01", "Lab02", ...],
    values: [0.5, -1.2, 2.4, ...],
    thresholds: [
        new ThresholdBand(-2, 2, 'conforme'),
        new ThresholdBand(-3, 3, 'discutable'),
    ]
);
*/
