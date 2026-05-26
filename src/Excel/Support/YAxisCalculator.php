<?php

namespace Procorad\ProcostatReporting\Excel\Support;

use Procorad\ProcostatReporting\Data\SampleAnalysisData;

/**
 * Computes the Y-axis upper bound for results line+marker charts.
 *
 * Takes the highest value of (activity + expanded uncertainty) across all labs,
 * then adds 10% breathing room above the top error bar tip.
 */
final class YAxisCalculator
{
    public function compute(SampleAnalysisData $analysis): float
    {
        $max = 0.0;
        foreach ($analysis->labResults as $lab) {
            if ($lab->activity !== null && $lab->expandedUncertainty !== null) {
                $max = max($max, $lab->activity + $lab->expandedUncertainty);
            } elseif ($lab->activity !== null) {
                $max = max($max, $lab->activity);
            }
        }
        return $max > 0.0 ? $max * 1.10 : $max;
    }
}
