<?php

namespace Procorad\ProcostatReporting\Excel\Charts;

use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use Procorad\ProcostatReporting\Data\SampleAnalysisData;
use Procorad\ProcostatReporting\Excel\Support\ExcelLayout;

/**
 * Builds the PhpSpreadsheet Chart skeleton for score bar charts.
 *
 * Generates a clustered barChart with a single data series (col B).
 * Threshold reference line data (cols D-G) and bar coloring (<c:dPt>)
 * are injected post-generation by BarChartPatcher.
 */
final class BarChartBuilder
{
    /**
     * @param string             $sheetName  Worksheet title (used in cell refs)
     * @param SampleAnalysisData $analysis
     * @param string             $yLabel     Axis label, e.g. 'Zeta', "Z'", '%'
     * @param int                $n          Number of lab rows
     */
    public function build(
        string             $sheetName,
        SampleAnalysisData $analysis,
        string             $yLabel,
        int                $n,
    ): Chart {
        $r1 = ExcelLayout::TABLE_START_ROW + 1;
        $r2 = ExcelLayout::TABLE_START_ROW + $n;

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, [$yLabel])],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheetName}'!\$A\${$r1}:\$A\${$r2}", null, $n)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheetName}'!\$B\${$r1}:\$B\${$r2}", null, $n)],
        );

        $chart = new Chart(
            'bar_' . md5($sheetName . $yLabel),
            new Title("{$analysis->isotope} {$yLabel} ({$analysis->sampleCode})"),
            null,
            new PlotArea(new Layout(), [$series]),
            true,
            DataSeries::EMPTY_AS_GAP,
            new Title('Laboratoire'),
            new Title($yLabel),
        );

        $chart->setTopLeftPosition(ExcelLayout::CHART_TOP_LEFT);
        $chart->setBottomRightPosition(ExcelLayout::CHART_BOTTOM_RIGHT_SCORE);

        return $chart;
    }
}
