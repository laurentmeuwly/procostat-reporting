<?php

namespace Procorad\ProcostatReporting\Excel\Patches;

use Procorad\ProcostatReporting\Excel\Ooxml\ChartDocument;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\AxisScaleDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\ErrorBarDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\LineDefinition;
use Procorad\ProcostatReporting\Excel\Ooxml\Definitions\MarkerDefinition;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;

/**
 * Post-generation OOXML patch for results line+marker charts.
 *
 * PhpSpreadsheet writes a generic chart XML skeleton.
 * This patcher applies per-series styles that the PhpSpreadsheet API doesn't support:
 *
 *   Series 0 — lab activities  : circle marker (blue), NO connecting line, symmetric error bars
 *   Series 1 — assigned value  : solid red line (1pt), no marker
 *   Series 2 — VA + uncertainty: dashed red line,      no marker
 *   Series 3 — VA - uncertainty: dashed red line,      no marker
 *
 * Y-axis is scaled from 0 to $yMax (activity max + 10%).
 */
final class ResultsChartPatcher
{
    /**
     * @param int    $chartIndex  0-based index in xl/charts/
     * @param string $sheetName   Worksheet title (used to build cell references)
     * @param int    $n           Number of lab data rows
     * @param float  $yMax        Y-axis upper bound
     */
    public function patch(
        ChartDocument $doc,
        int           $chartIndex,
        string        $sheetName,
        int           $n,
        float         $yMax,
    ): void {
        $r1     = ExcelLayout::TABLE_START_ROW + 1;
        $r2     = ExcelLayout::TABLE_START_ROW + $n;
        $errRef = "'{$sheetName}'!\$" . ExcelLayout::UNCERTAINTY_COL . "\${$r1}:\$" . ExcelLayout::UNCERTAINTY_COL . "\${$r2}";

        $doc->chart($chartIndex)
            ->series(0)
                ->addErrorBars(ErrorBarDefinition::symmetric($errRef))
                ->setMarker(MarkerDefinition::circle('4472C4'))
                ->setLine(LineDefinition::none())
            ->series(1)
                ->setLine(LineDefinition::solid('FF0000', 12700))
                ->setMarker(MarkerDefinition::none())
            ->series(2)
                ->setLine(LineDefinition::dashed('FF0000', 'dash', 12700))
                ->setMarker(MarkerDefinition::none())
            ->series(3)
                ->setLine(LineDefinition::dashed('FF0000', 'dash', 12700))
                ->setMarker(MarkerDefinition::none())
            ->yAxis()
                ->setScale(AxisScaleDefinition::fromZero($yMax))
                ->setNumberFormat('0.00E+00')
            ->save();
    }
}
