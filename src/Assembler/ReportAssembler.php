<?php

namespace Procorad\ProcostatReporting\Assembler;

use Procorad\ProcostatReporting\Model\ReportingResult;
use Procorad\ProcostatReporting\ValueObject\PlotSpec;
use Procorad\ProcostatReporting\ValueObject\PlotType;
use Procorad\ProcostatReporting\ValueObject\ReportingContext;
use Procorad\ProcostatReporting\ValueObject\Series;
use Procorad\ProcostatReporting\ValueObject\ThresholdBand;

final class ReportAssembler
{
    public function buildZScorePlot(
        ReportingResult $result,
        ReportingContext $context
    ): PlotSpec {

        $labels = [];
        $values = [];

        foreach ($result->labs as $labCode => $lab) {
            $labels[] = $labCode;
            $values[] = $lab->zScore;
        }

        $indicatorLabel = $result->primaryIndicator === 'z_prime'
            ? "z'-score"
            : "z-score";

        $series = new Series(
            labels: $labels,
            values: $values,
            label: "z'-score"
        );

        $thresholds = [
            new ThresholdBand(-2.0, 2.0, 'conforme'),
            new ThresholdBand(-3.0, 3.0, 'discutable'),
        ];

        return new PlotSpec(
            type: PlotType::BAR,
            title: "{$indicatorLabel} - {$context->comparisonCode} - {$context->isotope}",
            yLabel: $indicatorLabel,
            series: [$series],
            thresholds: $thresholds
        );
    }
}
